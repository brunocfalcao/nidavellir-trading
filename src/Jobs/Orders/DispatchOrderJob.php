<?php

namespace Nidavellir\Trading\Jobs\Orders;

use Illuminate\Support\Facades\Log;
use Nidavellir\Trading\Models\Order;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Models\Trader;
use Nidavellir\Trading\Models\Exchange;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Exceptions\DispatchOrderException;

/**
 * DispatchOrderJob handles the dispatching of trading orders
 * (Market, Limit, Profit). It processes orders based on type,
 * manages retry logic, handles sibling orders, and logs key
 * information for debugging. Ensures orders are processed
 * and dispatched to the exchange, adjusting parameters based
 * on market conditions and configurations.
 *
 * - Processes Market, Limit, and Profit orders.
 * - Manages retry logic and sibling dependencies.
 * - Logs significant actions and updates order status.
 */
class DispatchOrderJob extends AbstractJob
{
    // Constants for different order types: Market, Limit, and Profit.
    public const ORDER_TYPE_MARKET = 'MARKET';

    public const ORDER_TYPE_LIMIT = 'LIMIT';

    public const ORDER_TYPE_PROFIT = 'PROFIT';

    public const ORDER_TYPE_POSITION_CANCELLATION = 'POSITION-CANCELLATION';

    // Holds the order being dispatched.
    public Order $order;

    // Holds the order id argument.
    public $orderId;

    // Trader associated with the position.
    public Trader $trader;

    // The trading position associated with the order.
    public Position $position;

    // The exchange symbol associated with the order.
    public ExchangeSymbol $exchangeSymbol;

    // The exchange where the order will be placed.
    public Exchange $exchange;

    // The cryptocurrency symbol being traded.
    public Symbol $symbol;

    /**
     * Initializes the job with the specific order ID.
     * Preloads necessary entities to avoid repeated queries.
     */
    public function __construct(int $orderId)
    {
        // Retrieve the order and related entities.
        $this->orderId = $orderId;
        $this->order = Order::find($orderId);
        $this->trader = $this->order->position->trader;
        $this->position = $this->order->position;
        $this->exchangeSymbol = $this->order->position->exchangeSymbol;
        $this->symbol = $this->exchangeSymbol->symbol;
        $this->exchange = $this->order->position->trader->exchange;
    }

    /**
     * Main function to handle the dispatching of the order.
     * It checks the type of order, manages retries, and processes
     * sibling orders before dispatching the current one.
     */
    public function handle()
    {
        try {
            // Retrieve sibling orders excluding the current one.
            $siblings = $this->position->orders->where('id', '<>', $this->order->id);
            $siblingsLimitOnly = $siblings->where('type', self::ORDER_TYPE_LIMIT);

            // Handle retries and stop after 3 attempts.
            if ($this->attempts() == 3) {
                $this->order->update(['status' => 'error']);
                throw new DispatchOrderException(
                    message: 'Max attempts: Failed to create order on exchange',
                    additionalData: ['order_id' => $this->order->id],
                );
            }

            /**
             * If this order was previously retried and is already
             * in error, then don't retry again.
             */
            if ($this->order->status == 'error') {
                return;
            }

            /**
             * Verify it is a position cancellation order. On this case
             * we might have a trade in error, so we can't get the
             * next check verified.
             */
            if ($this->order->type == self::ORDER_TYPE_POSITION_CANCELLATION) {
                $this->syncPositionCancellationOrder();
                return;
            }

            /**
             * Only process order if the siblings are not in error,
             * there aren't pending limit orders to be processed,
             * and the last order is the one for profit and
             * everything else is complete.
             */
            if (! $siblings->contains('status', 'error') &&
                ! $this->shouldWaitForLimitOrdersToFinish($siblingsLimitOnly) &&
                ! $this->shouldWaitForAllOrdersExceptProfit($siblings)) {
                $this->processOrder();
            }
        } catch (\Throwable $e) {
            /**
             * Update order to error. Later the parent process
             * will deal with the trade coherency to cancel
             * any other orders that will be needed to
             * cancel or fill.
             */
            Log::info('Setting order id '. $this->order->id . ' to status ERROR');
            $this->order->update(['status' => 'error']);

            // Handle any errors and throw a custom exception.
            throw new TryCatchException(
                throwable: $e,
                additionalData: ['order_id' => $this->orderId]
            );
        }
    }

    protected function syncPositionCancellationOrder()
    {
        /**
         * Get position order for this position. On this case
         * we assume that the trader will not have the same
         * position token opened by himself. So we can
         * cancel the full position right away.
         */
        $positions = $this->trader
            ->withRESTApi()
            ->withPosition($this->order->position)
            ->withTrader($this->trader)
            ->withExchangeSymbol($this->exchangeSymbol)
            ->withOrder($this->order)
            ->getPositions();

        $positionAmount = collect($positions)
            ->where('positionAmt', '<>', 0)
            ->where(
                'symbol',
                $this->exchangeSymbol->symbol->token.'USDT'
            )
            ->sum('positionAmt');

        // With the position amount, lets open a contrary market order.
        $side = $positionAmount < 0 ? 'BUY' : 'SELL';
        $positionAmount = -$positionAmount;

        $sideDetails = $this->getOrderSideDetails($side);
        $this->placePositionCancellationOrder($positionAmount, $sideDetails);
    }

    /**
     * Determines if the Market order should wait for all
     * Limit orders to finish processing.
     */
    protected function shouldWaitForLimitOrdersToFinish($siblingsLimitOnly)
    {
        if ($this->order->type === self::ORDER_TYPE_MARKET &&
            $siblingsLimitOnly->contains('status', 'new')) {
            // Delay processing if any Limit orders are in "new" status.
            $this->release(5);

            return true;
        }

        return false;
    }

    /**
     * Determines if the Profit order should wait for other
     * orders to be processed before proceeding.
     */
    protected function shouldWaitForAllOrdersExceptProfit($siblings)
    {
        if ($this->order->type === self::ORDER_TYPE_PROFIT &&
            $siblings->contains('status', 'new')) {
            // Delay processing if any sibling orders are in "new" status.
            $this->release(5);

            return true;
        }

        return false;
    }

    /**
     * Processes the order by constructing the necessary data
     * and dispatching it to the exchange based on the order type.
     */
    protected function processOrder()
    {
        // Get the side of the order (buy/sell).
        $sideDetails = $this->getOrderSideDetails($this->symbol->side);

        // Build the payload for dispatching the order.
        $payload = $this->trader
            ->withRESTApi()
            ->withPosition($this->position)
            ->withTrader($this->trader)
            ->withExchangeSymbol($this->exchangeSymbol)
            ->withOrder($this->order);

        // Determine the price and quantity for the order.
        $orderPrice = $this->getPriceByRatio();
        $orderQuantity = $this->computeOrderAmount($orderPrice);

        // Dispatch the order to the exchange.
        $this->dispatchOrder($orderPrice, $orderQuantity, $sideDetails);
    }

    /**
     * Computes the amount of the token to be traded based on
     * total trade amount, leverage, and the price of the token.
     */
    protected function computeOrderAmount($price)
    {
        // For Market and Limit orders, calculate the token amount.
        if (in_array($this->order->type, [self::ORDER_TYPE_MARKET, self::ORDER_TYPE_LIMIT])) {
            $amountAfterDivider = $this->position->total_trade_amount / $this->order->amount_divider;
            $amountAfterLeverage = $amountAfterDivider * $this->position->leverage;
            $tokenAmountToBuy = $amountAfterLeverage / $price;

            return round($tokenAmountToBuy, $this->exchangeSymbol->precision_quantity);
        }

        // For Profit orders, use a predefined amount.
        if ($this->order->type == self::ORDER_TYPE_PROFIT) {
            return 100;  // Example hardcoded value, adjust as necessary.
        }
    }

    /**
     * Dispatches the order to the exchange based on the order type.
     */
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
                $this->placeProfitOrder($orderPrice, $sideDetails);
                break;
        }
    }

    protected function placePositionCancellationOrder($orderQuantity, $sideDetails)
    {
        // Build the API order data for a Market order.
        $this->orderData = [
            'newClientOrderId' => $this->generateClientOrderId(),
            'side' => strtoupper($sideDetails['orderMarketSide']),
            'type' => 'MARKET',
            'quantity' => $orderQuantity,
            'symbol' => $this->symbol->token.'USDT',
        ];

        info_multiple(
            '-- Order Placement (POSITION CANCELLATION) --',
            'Nidavellir Order id:'.$this->order->id,
            'newClientOrderId: '.$this->orderData['newClientOrderId'],
            'side: '.$this->orderData['side'],
            'type: '.'MARKET',
            'quantity: '.$this->orderData['quantity'],
            'symbol: '.$this->orderData['symbol'],
            ' '
        );

        // Dispatch the order via the trader's API.
        $result = $this->trader
            ->withRESTApi()
            ->withOptions($this->orderData)
            ->withPosition($this->position)
            ->withTrader($this->trader)
            ->withExchangeSymbol($this->exchangeSymbol)
            ->withOrder($this->order)
            ->placeSingleOrder();

        // Update the order with the result from the exchange.
        $this->updateOrderWithExchangeResult($result);

        /**
         * The average price, and the filled quantity is not returned.
         * So, we need to synchronously query the market order to
         * obtain the remaining data. Synchronously because the profit
         * order needs the market order quantity.
         */
        $this->updateOrderWithQueryOnOrderId($this->order->id);

        /**
         * Finally, everything went well, lets update the status
         * of the profit order to cancelled.
         */
        $this->order
            ->position
            ->orders()
            ->where(
                'type',
                'PROFIT'
            )->update([
                'status' => 'cancelled',
            ]);
    }

    /**
     * Places a Market order on the exchange.
     */
    protected function placeMarketOrder($orderQuantity, $sideDetails)
    {
        // Build the API order data for a Market order.
        $this->orderData = [
            'newClientOrderId' => $this->generateClientOrderId(),
            'side' => strtoupper($sideDetails['orderMarketSide']),
            'type' => 'MARKET',
            'quantity' => $orderQuantity,
            'symbol' => $this->symbol->token.'USDT',
        ];

        info_multiple(
            '-- Order Placement (MARKET) --',
            'Nidavellir Order id:'.$this->order->id,
            'newClientOrderId: '.$this->orderData['newClientOrderId'],
            'side: '.$this->orderData['side'],
            'type: '.'MARKET',
            'quantity: '.$this->orderData['quantity'],
            'symbol: '.$this->orderData['symbol'],
            ' '
        );

        //return;

        // Dispatch the order via the trader's API.
        $result = $this->trader
            ->withRESTApi()
            ->withOptions($this->orderData)
            ->withPosition($this->position)
            ->withTrader($this->trader)
            ->withExchangeSymbol($this->exchangeSymbol)
            ->withOrder($this->order)
            ->placeSingleOrder();

        // Update the order with the result from the exchange.
        $this->updateOrderWithExchangeResult($result);

        /**
         * The average price, and the filled quantity is not returned.
         * So, we need to synchronously query the market order to
         * obtain the remaining data. Synchronously because the profit
         * order needs the market order quantity.
         */
        $this->updateOrderWithQueryOnOrderId($this->order->id);
    }

    /**
     * Retrieves the full order data so we can obtain the remaining
     * order data (avgPrice and excutedQty).
     */
    protected function updateOrderWithQueryOnOrderId($orderId)
    {
        $this->order->refresh();

        $result = $this->trader
            ->withRESTApi()
            ->withPosition($this->position)
            ->withTrader($this->trader)
            ->withExchangeSymbol($this->exchangeSymbol)
            ->withOrder($this->order)
            ->withOptions([
                'symbol' => $this->symbol->token.'USDT',
                'orderId' => $this->order->order_exchange_system_id,
            ])
            ->getOrder();

        // Lets ensure the order is FILLED.
        if ($result['status'] != 'FILLED') {
            throw new DispatchOrderException(
                message: 'Market order was executed but it is not FILLED',
                additionalData: ['order_id' => $this->order->id],
            );
        }

        // Update the order details with exchange result.
        $this->order->update([
            'filled_quantity' => $result['executedQty'],
            'filled_average_price' => $result['avgPrice'],
        ]);
    }

    // Generates a custom business order id saved on the order.
    protected function generateClientOrderId()
    {
        return
                // Custom client order id generation.
                /*
                $this->trader->id.
                '-'.$this->exchange->id.
                '-'.$this->position->id.
                '-'.
                */
                $this->order->id;
    }

    /**
     * Places a Limit order on the exchange.
     */
    protected function placeLimitOrder($orderPrice, $orderQuantity, $sideDetails)
    {
        if ($this->order->id == 3) {
            throw new \Exception('!!! Forced Order Exception !!!');
        }

        // Update the order with the computed entry data.
        $this->order->update([
            'entry_average_price' => $orderPrice,
            'entry_quantity' => $orderQuantity,
        ]);

        // Build the API order data for a Limit order.
        $this->orderData = [
            'timeInForce' => 'GTC',
            'side' => strtoupper($sideDetails['orderLimitBuy']),
            'type' => 'LIMIT',
            'quantity' => $orderQuantity,
            'newClientOrderId' => $this->generateClientOrderId(),
            'symbol' => $this->exchangeSymbol->symbol->token.'USDT',
            'price' => $orderPrice,
        ];

        info_multiple(
            '-- Order Placement (LIMIT) --',
            'Nidavellir Order id:'.$this->order->id,
            'newClientOrderId: '.$this->orderData['newClientOrderId'],
            'side: '.$this->orderData['side'],
            'type: '.'LIMIT',
            'quantity: '.$this->orderData['quantity'],
            'symbol: '.$this->orderData['symbol'],
            'price:'.$this->orderData['price'],
            ' '
        );

        //return;

        // Dispatch the order via the trader's API.
        $result = $this->trader
            ->withRESTApi()
            ->withOptions($this->orderData)
            ->withPosition($this->position)
            ->withTrader($this->trader)
            ->withExchangeSymbol($this->exchangeSymbol)
            ->withOrder($this->order)
            ->placeSingleOrder();

        // Update the order with the result from the exchange.
        $this->updateOrderWithExchangeResult($result);
    }

    /**
     * Places a Profit order on the exchange.
     */
    protected function placeProfitOrder($orderPrice, $sideDetails)
    {
        // Update the order with the computed entry data.
        $this->order->update([
            'entry_average_price' => $orderPrice,
        ]);

        /**
         * This profit order placed, will be with the same filled quantity
         * as the market order, since we will just start by
         * selling what we had filled.
         */
        $marketOrderQuantity = $this->order
            ->position
            ->orders
            ->firstWhere('type', 'MARKET')
                                    ->filled_quantity;

        // Build the API order data for a Limit order.
        $this->orderData = [
            'timeInForce' => 'GTC',
            'side' => strtoupper($sideDetails['orderLimitProfit']),
            'type' => 'LIMIT',
            'reduceOnly' => 'true',
            'quantity' => $marketOrderQuantity,
            'newClientOrderId' => $this->generateClientOrderId(),
            'symbol' => $this->exchangeSymbol->symbol->token.'USDT',
            'price' => $orderPrice,
        ];

        info_multiple(
            '-- Order Placement (PROFIT) --',
            'Nidavellir Order id:'.$this->order->id,
            'newClientOrderId: '.$this->orderData['newClientOrderId'],
            'side: '.$this->orderData['side'],
            'type: '.'LIMIT',
            'quantity: '.$this->orderData['quantity'],
            'symbol: '.$this->orderData['symbol'],
            'price:'.$this->orderData['price'],
            ' '
        );

        // Dispatch the order via the trader's API.
        $result = $this->trader
            ->withRESTApi()
            ->withOptions($this->orderData)
            ->withPosition($this->position)
            ->withTrader($this->trader)
            ->withExchangeSymbol($this->exchangeSymbol)
            ->withOrder($this->order)
            ->placeSingleOrder();

        // Update the order with the result from the exchange.
        $this->updateOrderWithExchangeResult($result);
    }

    /**
     * Updates the order in the system with the result
     * from the exchange.
     */
    protected function updateOrderWithExchangeResult(array $result)
    {
        // Update the order details with exchange result.
        $this->order->update([
            'order_exchange_system_id' => $result['orderId'],
            'filled_quantity' => $result['executedQty'],
            'filled_average_price' => $result['avgPrice'],
            'api_result' => $result,
            'status' => 'synced',
        ]);
    }

    /**
     * Determines the correct order side (buy/sell) based
     * on the current position's trade configuration.
     */
    protected function getOrderSideDetails($side)
    {
        if ($side === 'BUY') {
            return [
                'orderMarketSide' => 'BUY',
                'orderLimitBuy' => 'BUY',
                'orderLimitProfit' => 'SELL',
            ];
        }

        return [
            'orderMarketSide' => 'SELL',
            'orderLimitBuy' => 'SELL',
            'orderLimitProfit' => 'BUY',
        ];
    }

    /**
     * Calculates the price for the order by adjusting
     * the mark price according to a configured ratio.
     */
    protected function getPriceByRatio()
    {
        $markPrice = $this->position->initial_mark_price;
        $precision = $this->exchangeSymbol->precision_price;
        $priceRatio = $this->order->price_ratio_percentage / 100;
        $side = $this->position->side;

        $orderPrice = 0;

        // Determine the order price based on whether it's a Buy or Sell.
        if ($side === 'BUY') {
            $orderPrice = $this->order->type !== self::ORDER_TYPE_PROFIT
                ? round($markPrice - ($markPrice * $priceRatio), $precision)
                : round($markPrice + ($markPrice * $priceRatio), $precision);
        }

        if ($side === 'SELL') {
            $orderPrice = $this->order->type !== self::ORDER_TYPE_PROFIT
                ? round($markPrice + ($markPrice * $priceRatio), $precision)
                : round($markPrice - ($markPrice * $priceRatio), $precision);
        }

        // Adjust the computed price to the correct tick size.
        return $this->adjustPriceToTickSize($orderPrice, $this->exchangeSymbol->tick_size);
    }

    /**
     * Adjusts the order price to match the exchange's tick size.
     */
    protected function adjustPriceToTickSize($price, $tickSize)
    {
        return floor($price / $tickSize) * $tickSize;
    }
}
