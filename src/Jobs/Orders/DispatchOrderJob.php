<?php

namespace Nidavellir\Trading\Jobs\Orders;

use Illuminate\Support\Str;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\DispatchOrderException;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Models\ApiSystem;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Order;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Models\Trader;

/**
 * Class: DispatchOrderJob
 *
 * This job handles the process of dispatching orders on the exchange.
 * It manages various order types (MARKET, LIMIT, PROFIT, POSITION-CANCELLATION)
 * and ensures that orders are correctly placed based on the position's state.
 *
 * Important points:
 * - Validates if the order exists and belongs to a valid position.
 * - Processes different order types using the relevant trading methods.
 */
class DispatchOrderJob extends AbstractJob
{
    // Constant values representing different order types.
    public const ORDER_TYPE_MARKET = 'MARKET';
    public const ORDER_TYPE_LIMIT = 'LIMIT';
    public const ORDER_TYPE_PROFIT = 'PROFIT';
    public const ORDER_TYPE_POSITION_CANCELLATION = 'POSITION-CANCELLATION';

    // The order instance being dispatched.
    public Order $order;

    // ID of the order being processed.
    public $orderId;

    // The trader associated with the order.
    public Trader $trader;

    // The position associated with the order.
    public Position $position;

    // The exchange symbol associated with the order.
    public ExchangeSymbol $exchangeSymbol;

    // The API system being used for trading.
    public ApiSystem $apiSystem;

    // The symbol representing the asset being traded.
    public Symbol $symbol;

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
        $this->order = Order::find($orderId);

        // Initialize the trader, position, exchange symbol, and symbol models.
        $this->trader = $this->order->position->trader;
        $this->position = $this->order->position;
        $this->exchangeSymbol = $this->order->position->exchangeSymbol;
        $this->symbol = $this->exchangeSymbol->symbol;
        $this->exchange = $this->order->position->trader->exchange;
    }

    // Main handle method to process the dispatching of the order.
    public function handle()
    {
        try {
            // Retrieve sibling orders associated with the same position.
            $siblings = $this->position->orders->where('id', '<>', $this->order->id);
            $siblingsLimitOnly = $siblings->where('type', self::ORDER_TYPE_LIMIT);

            // Check if the maximum number of attempts to dispatch has been reached.
            if ($this->attempts() == 3) {
                $this->order->update(['status' => 'error']);
                throw new DispatchOrderException(
                    message: 'Max attempts: Failed to create order on exchange',
                    additionalData: ['order_id' => $this->order->id],
                );
            }

            // If the order status is 'error', exit the method.
            if ($this->order->status == 'error') {
                return;
            }

            // Process cancellation orders for the entire position.
            if ($this->order->type == self::ORDER_TYPE_POSITION_CANCELLATION) {
                $this->syncPositionCancellationOrder();
                return;
            }

            // Check conditions to process the order only when eligible.
            if (!$siblings->contains('status', 'error') &&
                !$this->shouldWaitForLimitOrdersToFinish($siblingsLimitOnly) &&
                !$this->shouldWaitForAllOrdersExceptProfit($siblings)) {
                $this->processOrder();
            }
            $this->jobPollerInstance->markAsComplete();
        } catch (\Throwable $e) {
            $this->jobPollerInstance->markAsError($e);
            // Mark the order status as 'error' if an exception occurs.
            $this->order->update(['status' => 'error']);

            // Dispatch a cancellation job for this order.
            CancelOrderJob::dispatch($this->order->id);

            // Throw a TryCatchException to handle the error.
            throw new TryCatchException(
                throwable: $e,
                additionalData: ['order_id' => $this->orderId]
            );
        }
    }

    // Synchronizes the cancellation of a position's order.
    protected function syncPositionCancellationOrder()
    {
        // Retrieve the trader's current open positions using REST API.
        $positions = $this->trader
            ->withRESTApi()
            ->withLoggable($this->order)
            ->getPositions();

        // Calculate the total amount of the current position.
        $positionAmount = collect($positions)
            ->where('positionAmt', '<>', 0)
            ->where('symbol', $this->exchangeSymbol->symbol->token . 'USDT')
            ->sum('positionAmt');

        // Determine if the position is LONG or SHORT and set the details accordingly.
        $side = $positionAmount < 0 ? 'LONG' : 'SHORT';
        $positionAmount = abs($positionAmount);

        $sideDetails = $this->getOrderSideDetails($side);

        // Place a cancellation order for the position.
        $this->placePositionCancellationOrder($positionAmount, $sideDetails);
    }

    // Determines if the job should wait for limit orders to complete before proceeding.
    protected function shouldWaitForLimitOrdersToFinish($siblingsLimitOnly)
    {
        // Check if the current order is of type MARKET and limit orders are still 'new'.
        if ($this->order->type === self::ORDER_TYPE_MARKET &&
            $siblingsLimitOnly->contains('status', 'new')) {
            $this->release(5); // Delay the job execution.
            return true;
        }

        return false;
    }

    // Determines if the job should wait for all orders except the profit order.
    protected function shouldWaitForAllOrdersExceptProfit($siblings)
    {
        // Check if the current order is PROFIT and other orders are still 'new'.
        if ($this->order->type === self::ORDER_TYPE_PROFIT &&
            $siblings->contains('status', 'new')) {
            $this->release(5); // Delay the job execution.
            return true;
        }

        return false;
    }

    // Processes the current order based on its type.
    protected function processOrder()
    {
        // Retrieve the side details (BUY/SELL) based on the symbol's side.
        $sideDetails = $this->getOrderSideDetails($this->symbol->side);

        // Calculate the order price and quantity based on ratios and leverage.
        $orderPrice = $this->getPriceByRatio();
        $orderQuantity = $this->computeOrderQuantity($orderPrice);

        // Dispatch the order to the exchange using the calculated details.
        $this->dispatchOrder($orderPrice, $orderQuantity, $sideDetails);
    }

    // Computes the quantity of the order based on price and leverage.
    protected function computeOrderQuantity($price)
    {
        // Calculate quantity for MARKET and LIMIT orders.
        if (in_array($this->order->type, [self::ORDER_TYPE_MARKET, self::ORDER_TYPE_LIMIT])) {
            return round(
                ($this->position->total_trade_amount / $this->order->amount_divider * $this->position->leverage) / $price,
                $this->exchangeSymbol->precision_quantity
            );
        }

        // Use filled quantity for PROFIT orders.
        if ($this->order->type == self::ORDER_TYPE_PROFIT) {
            return $this->order->position->orders->firstWhere('type', 'MARKET')->filled_quantity;
        }
    }

    // Dispatches the order based on its type (LIMIT, MARKET, or PROFIT).
    protected function dispatchOrder($orderPrice, $orderQuantity, $sideDetails)
    {
        switch ($this->order->type) {
            case self::ORDER_TYPE_LIMIT:
                $this->placeLimitOrder($orderPrice, $orderQuantity, $sideDetails);
                break;
            case self::ORDER_TYPE_MARKET:
                $this->placeMarketOrder($orderQuantity, $sideDetails);
                break;
            case self::ORDER_TYPE_PROFIT:
                $this->placeProfitOrder($orderPrice, $orderQuantity, $sideDetails);
                break;
        }
    }

    // Updates the order in the system with the exchange's execution results.
    protected function updateOrderWithExchangeResult(array $result)
    {
        $this->order->update([
            'order_exchange_system_id' => $result['orderId'],
            'filled_quantity' => $result['executedQty'],
            'filled_average_price' => $result['avgPrice'],
            'api_result' => $result,
            'status' => 'synced',
        ]);
    }

    // Retrieves the side details for the order (BUY/SELL logic).
    protected function getOrderSideDetails($side)
    {
        if ($side == 'LONG') {
            return [
                'orderMarketSide' => 'BUY',
                'orderLimitBuy' => 'BUY',
                'orderLimitProfit' => 'SELL',
            ];
        }

        if ($side == 'SHORT') {
            return [
                'orderMarketSide' => 'SELL',
                'orderLimitBuy' => 'SELL',
                'orderLimitProfit' => 'BUY',
            ];
        }

        // Throw an exception if the side is not recognized.
        throw new DispatchOrderException(
            message: 'Symbol side empty, null, or unknown',
            additionalData: ['order_id' => $this->order->id]
        );
    }

    // Adjusts the calculated order price to fit the tick size.
    protected function adjustPriceToTickSize($price, $tickSize)
    {
        return floor($price / $tickSize) * $tickSize;
    }
}
