<?php

namespace Nidavellir\Trading\Jobs\Orders;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\OrderNotSyncedException;
use Nidavellir\Trading\Models\ApplicationLog;
use Nidavellir\Trading\Models\Exchange;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Order;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Models\Trader;
use Throwable;

class DispatchOrderJob extends AbstractJob
{
    // Constants for different order types: Market, Limit, and Profit.
    public const ORDER_TYPE_MARKET = 'MARKET';

    public const ORDER_TYPE_LIMIT = 'LIMIT';

    public const ORDER_TYPE_PROFIT = 'PROFIT';

    public Order $order;

    public Trader $trader;

    public Position $position;

    public ExchangeSymbol $exchangeSymbol;

    public Exchange $exchange;

    public Symbol $symbol;

    /**
     * Initializes the job with a specific order ID
     * that is used to retrieve the order for processing.
     */
    public function __construct(int $orderId)
    {
        /**
         * Set variables that will be used everywhere, to avoid
         * queries being generated again
         */
        $this->order = Order::find($orderId);
        $this->trader = $this->order->position->trader;
        $this->position = $this->order->position;
        $this->exchangeSymbol = $this->order->position->exchangeSymbol;
        $this->symbol = $this->exchangeSymbol->symbol;
        $this->exchange = $this->order->position->exchange;
    }

    /**
     * Main function that handles the dispatching of the order.
     * It performs necessary checks and processes based on
     * the type of order and related sibling orders.
     */
    public function handle()
    {
        try {
            ApplicationLog::withActionCanonical('order.dispatch')
                ->withDescription('Job started')
                ->withLoggable($this->order)
                ->saveLog();

            // Retrieve sibling orders associated with the same position,
            // excluding the current order. Filters for only limit orders.
            $siblings = $this->position->orders->where('id', '<>', $this->order->id);
            $siblingsLimitOnly = $siblings->where('type', self::ORDER_TYPE_LIMIT);

            // Retry logic: if attempts reach 3, throw an exception.
            if ($this->attempts() == 3) {
                $this->order->update(['status' => 'error']);
                throw new OrderNotSyncedException(
                    'Max attempts: Failed to create order on exchange, with ID: '.
                    ['order_id' => $this->order->id],
                    $this->order
                );

                return;
            }

            // If any sibling order has an error, stop further processing.
            if ($siblings->contains('status', 'error')) {
                return;
            }

            // Check conditions specific to Market or Profit orders before proceeding.
            if ($this->shouldWaitForLimitOrdersToFinish(
                $this->order,
                $siblingsLimitOnly
            )) {
                return;
            }

            if ($this->shouldWaitForAllOrdersExceptProfit(
                $this->order,
                $siblings
            )) {
                return;
            }

            // Process the order if all checks pass.
            $this->processOrder();
        } catch (Throwable $e) {
            $this->order->update(['status' => 'error']);

            // Handle any exceptions by throwing a custom OrderNotSyncedException.
            throw new OrderNotSyncedException(
                $e->getMessage(),
                ['order_id' => $this->order->id],
                $this->order
            );
        }
    }

    /**
     * Determines if the current Market order should wait for
     * all Limit orders to be processed before proceeding.
     */
    private function shouldWaitForLimitOrdersToFinish($siblingsLimitOnly)
    {
        if ($this->order->type === self::ORDER_TYPE_MARKET &&
            $siblingsLimitOnly->contains('status', 'new')) {
            // If any Limit orders are still in "new" status, pause processing.
            $this->release(5);

            return true;
        }

        return false;
    }

    /**
     * Determines if the current Profit order should wait for
     * other orders to be processed before proceeding.
     */
    private function shouldWaitForAllOrdersExceptProfit($siblings)
    {
        if ($this->order->type === self::ORDER_TYPE_PROFIT && $siblings->contains('status', 'new')) {
            // If any sibling orders are still in "new" status, pause processing.
            $this->release(5);

            return true;
        }

        return false;
    }

    /**
     * Processes the order by constructing the necessary data
     * and dispatching it to the exchange, based on the order type.
     */
    private function processOrder()
    {
        ApplicationLog::withActionCanonical('order.dispatch')
            ->withDescription('Process Order started')
            ->withLoggable($this->order)
            ->saveLog();

        // Determine the side of the order (buy/sell).
        $sideDetails = $this->getOrderSideDetails(
            config('nidavellir.positions.current_side')
        );

        ApplicationLog::withActionCanonical('order.dispatch')
            ->withDescription('Attribute $sideDetails')
            ->withReturnData($sideDetails)
            ->withLoggable($this->order)
            ->saveLog();

        // Build the payload for the API call.
        $payload = $this->trader
            ->withRESTApi()
            ->withPosition($this->position)
            ->withTrader($this->trader)
            ->withExchangeSymbol($this->exchangeSymbol)
            ->withOrder($this->order);

        ApplicationLog::withActionCanonical('order.dispatch')
            ->withDescription('Attribute $payload')
            ->withReturnData($payload)
            ->withLoggable($this->order)
            ->saveLog();

        // Calculate the price of the order based on its type and market conditions.
        $orderPrice = $this->getPriceByRatio($this->position->initial_mark_price);

        // Compute the amount of the asset to be traded in the order.
        $orderQuantity = $this->computeOrderAmount($orderPrice);

        // Log the order details for debugging purposes.
        $this->logOrderDetails($orderQuantity, $orderPrice);

        // Dispatch the order based on its type (Market, Limit, Profit).
        $this->dispatchOrder($orderPrice, $orderQuantity, $sideDetails);
    }

    /**
     * Computes the amount of the token to be traded based on
     * the total trade amount, leverage, and the price of the token.
     */
    private function computeOrderAmount($price)
    {
        // Handles both MARKET and LIMIT order types.
        if (in_array($this->order->type, [self::ORDER_TYPE_MARKET, self::ORDER_TYPE_LIMIT])) {

            /**
             * Calculate the token amount to buy, factoring in
             * leverage and dividing by the configured price.
             */
            $amountAfterDivider = $this->position->total_trade_amount / $this->order->amount_divider;
            $amountAfterLeverage = $amountAfterDivider * $this->position->leverage;
            $tokenAmountToBuy = $amountAfterLeverage / $price;

            return round($tokenAmountToBuy, $exchangeSymbol->precision_quantity);
        }

        if ($this->order->type == self::ORDER_TYPE_PROFIT) {
            /**
             * If it's a profit order, since we are creating it for the first
             * time, the amount is the same as the one from the market order.
             * That's the orders[type=MARKET].filled_amount.
             *
             * For the price, we need to get the new price given the
             * profit price ration, and then use that to calculate the
             * new amount (with this trade leverage).
             */
            $marketOrder = $this->position
                ->orders
                ->firstWhere('type', 'MARKET');

            return 100;
        }
    }

    /**
     * Dispatches the order to the exchange, adjusting the
     * logic based on whether it is a Market, Limit, or Profit order.
     */
    private function dispatchOrder($orderPrice, $orderQuantity, $sideDetails)
    {
        switch ($this->order->type) {
            case self::ORDER_TYPE_LIMIT:
                $this->placeLimitOrder($orderPrice, $orderQuantity, $sideDetails);
                break;
            case self::ORDER_TYPE_MARKET:
                $this->placeMarketOrder($orderQuantity, $sideDetails);
                break;
            case self::ORDER_TYPE_PROFIT:
                $this->placeProfitOrder($orderQuantity, $sideDetails);
                break;
        }
    }

    /**
     * Places a Market order on the exchange, constructing
     * the necessary payload and submitting it via API.
     */
    private function placeMarketOrder($orderQuantity, $sideDetails)
    {
        $this->orderData = [
            /**
             * Composition of the different id's related to this order.
             * Trader Id (T)
             * Exchange Id (EX)
             * Position Id (P)
             * Order Id (O)
             *
             * We can split it by the '-' and get the different values.
             */
            'newClientOrderId' => 'T:'.$this->trader.
                '-EX:'.$this->exchange->id.
                '-P:'.$this->position->id.
                '-O:'.$this->order->id,
            'side' => strtoupper($sideDetails['orderSide']),
            'type' => 'MARKET',
            'quantity' => $orderQuantity,
            // TODO: For another exchange is not like this.
            'symbol' => $this->symbol->token.'USDT',
        ];

        // Dispatch the order via the trader's API.
        $result = $this->trader
            ->withRESTApi()
            ->withOptions($this->orderData)
            ->withPosition($this->position)
            ->withTrader($this->trader)
            ->withExchangeSymbol($this->exchangeSymbol)
            ->withOrder($this->order)
            ->placeSingleOrder();

        $this->updateOrderWithExchangeResult($result);
    }

    /**
     * Places a Limit order on the exchange, constructing
     * the payload with price and quantity details.
     */
    private function placeLimitOrder($orderPrice, $orderQuantity, $sideDetails)
    {
        // Lets update the order with the final computed entry data.
        $this->order->update([
            'entry_average_price' => $orderPrice,
            'entry_quantity' => $orderQuantity,
        ]);

        // Create the api order data to be sent to the exchange.
        $this->orderData = [
            'timeInForce' => 'GTC',
            'side' => strtoupper($sideDetails['orderSide']),
            'type' => 'LIMIT',
            'quantity' => $orderQuantity,
            'newClientOrderId' => 'EX:'.$this->exchangeSymbol->exchange->id.'-P:'.$this->order->position->id.'-O:'.$this->order->id,
            'symbol' => $this->exchangeSymbol->symbol->token.'USDT',
            'price' => $orderPrice,
        ];

        // Dispatch the order via the trader's API.
        $result = $this->trader
            ->withRESTApi()
            ->withOptions($this->orderData)
            ->withPosition($this->position)
            ->withTrader($this->trader)
            ->withExchangeSymbol($this->exchangeSymbol)
            ->withOrder($this->order)
            ->placeSingleOrder();

        $this->updateOrderWithExchangeResult($result);
    }

    private function updateOrderWithExchangeResult(array $result)
    {
        // Update order with the order system id and result payload.
        $this->order->update([
            'order_exchange_id' => $result['orderId'],
            'filled_quantity' => $result['executedQty'],
            'filled_average_price' => $result['avgPrice'],
            'api_result' => $result,
            'status' => 'synced',
        ]);
    }

    /**
     * Logs details of the order for debugging purposes, such as
     * the order type, price, and amount.
     */
    private function logOrderDetails($orderQuantity, $orderPrice)
    {
        info_multiple(
            '=== ORDER ID '.$this->order->id,
            'Type: '.$this->order->type,
            'Token: '.$this->symbol->token,
            'Total Trade Amount: '.$this->position->total_trade_amount,
            'Token Price: '.round($this->position->initial_mark_price, $this->exchangeSymbol->precision_price),
            'Amount Divider: '.$this->order->amount_divider,
            'Ratio: '.$this->order->price_ratio_percentage,
            'Order Price: '.$orderPrice,
            'Order amount: '.$orderQuantity,
            'Order amount (USDT): '.$orderQuantity * $orderPrice,
            '===',
            ' '
        );
    }

    /**
     * Determines the correct order side (buy/sell) based
     * on the current position's trade configuration.
     */
    private function getOrderSideDetails($side)
    {
        if ($side === 'BUY') {
            return [
                'orderSide' => 'buy',
                'orderLimitBuy' => 'buy',
                'orderLimitProfit' => 'sell',
            ];
        }

        return [
            'orderSide' => 'sell',
            'orderLimitBuy' => 'sell',
            'orderLimitProfit' => 'buy',
        ];
    }

    /**
     * Calculates the price for the order by adjusting
     * the mark price according to a configured ratio.
     */
    private function getPriceByRatio($markPrice)
    {
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

        /**
         * Adjust the computed price to the correct tick size
         * to ensure the order is not rejected by the exchange.
         */
        $priceTickSizeAdjusted = $this->adjustPriceToTickSize(
            $orderPrice,
            $this->exchangeSymbol->tick_size
        );

        return $priceTickSizeAdjusted;
    }

    /**
     * Adjusts the order price to match the exchange's tick size
     * to ensure the price is valid for placing the order.
     */
    private function adjustPriceToTickSize($price, $tickSize)
    {
        return floor($price / $tickSize) * $tickSize;
    }
}
