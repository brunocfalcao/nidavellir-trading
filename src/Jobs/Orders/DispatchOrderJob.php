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

/**
 * Class: DispatchOrderJob
 *
 * This class handles the dispatching of trading orders (Market, Limit, Profit).
 * It processes the order based on its type, manages retry logic, handles sibling
 * orders, and logs key information for debugging purposes. This class ensures
 * that orders are correctly processed and dispatched to the exchange, adjusting
 * order parameters and price according to market conditions and configuration.
 *
 * Important points:
 * - Processes Market, Limit, and Profit orders.
 * - Manages retry logic and sibling order dependencies.
 * - Logs all significant actions and data points for debugging.
 * - Updates the order status and records relevant results.
 */
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
         * Preload necessary entities to avoid repeated
         * database queries during processing.
         */
        $this->order = Order::find($orderId);
        $this->trader = $this->order->position->trader;
        $this->position = $this->order->position;
        $this->exchangeSymbol = $this->order->position->exchangeSymbol;
        $this->symbol = $this->exchangeSymbol->symbol;
        $this->exchange = $this->order->position->trader->exchange;
    }

    /**
     * Main function that handles the dispatching of the order.
     * It performs necessary checks and processes based on
     * the type of order and related sibling orders.
     */
    public function handle()
    {
        try {
            /**
             * Log the initiation of the order dispatch process.
             */
            ApplicationLog::withActionCanonical('order.dispatch')
                ->withDescription('Job started')
                ->withLoggable($this->order)
                ->saveLog();

            // Retrieve sibling orders excluding the current one.
            $siblings = $this->position->orders->where('id', '<>', $this->order->id);
            $siblingsLimitOnly = $siblings->where('type', self::ORDER_TYPE_LIMIT);

            /**
             * Handle retries: if the job has reached 3 attempts,
             * update the order to "error" and throw an exception.
             */
            if ($this->attempts() == 3) {
                $this->order->update(['status' => 'error']);
                throw new OrderNotSyncedException(
                    'Max attempts: Failed to create order on exchange, with ID: '.
                    ['order_id' => $this->order->id],
                    $this->order
                );
            }

            /**
             * Stop processing if any sibling orders have errors.
             */
            if ($siblings->contains('status', 'error')) {
                return;
            }

            /**
             * For Market orders, wait for all Limit orders to be processed.
             */
            if ($this->shouldWaitForLimitOrdersToFinish($siblingsLimitOnly)) {
                return;
            }

            /**
             * For Profit orders, wait for other orders to be processed.
             */
            if ($this->shouldWaitForAllOrdersExceptProfit($siblings)) {
                return;
            }

            // Proceed to process the order if all checks are passed.
            $this->processOrder();
        } catch (Throwable $e) {
            /**
             * Handle any errors by throwing a custom exception.
             */
            throw new OrderNotSyncedException(
                $e,
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
            // If any Limit orders are still in "new" status, delay the processing.
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
            // If any sibling orders are still in "new" status, delay the processing.
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

        /**
         * Determine the side of the order (buy/sell).
         */
        $sideDetails = $this->getOrderSideDetails(
            config('nidavellir.positions.current_side')
        );

        // Log the side details of the order.
        ApplicationLog::withActionCanonical('order.dispatch')
            ->withDescription('Attribute $sideDetails')
            ->withReturnData($sideDetails)
            ->withLoggable($this->order)
            ->saveLog();

        /**
         * Build the payload that will be used to dispatch the order.
         */
        $payload = $this->trader
            ->withRESTApi()
            ->withPosition($this->position)
            ->withTrader($this->trader)
            ->withExchangeSymbol($this->exchangeSymbol)
            ->withOrder($this->order);

        // Log the payload details for debugging.
        ApplicationLog::withActionCanonical('order.dispatch')
            ->withDescription('Attribute $payload')
            ->withReturnData($payload)
            ->withLoggable($this->order)
            ->saveLog();

        /**
         * Determine the price and quantity for the order.
         */
        $orderPrice = $this->getPriceByRatio($this->position->initial_mark_price);
        $orderQuantity = $this->computeOrderAmount($orderPrice);

        /**
         * Log the order details (price, quantity, etc.) for debugging.
         */
        $this->logOrderDetails($orderQuantity, $orderPrice);

        // Dispatch the order to the exchange based on the order type.
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

            return round($tokenAmountToBuy, $this->exchangeSymbol->precision_quantity);
        }

        if ($this->order->type == self::ORDER_TYPE_PROFIT) {
            /**
             * If it's a profit order, since we are creating it for the first
             * time, the amount is the same as the one from the market order.
             */
            $marketOrder = $this->position
                ->orders
                ->firstWhere('type', 'MARKET');

            return 100;  // Example hardcoded value, adjust as necessary.
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
        // Build the necessary API order data for a Market order.
        $this->orderData = [
            'newClientOrderId' => 'T:'.$this->trader.
                '-EX:'.$this->exchange->id.
                '-P:'.$this->position->id.
                '-O:'.$this->order->id,
            'side' => strtoupper($sideDetails['orderSide']),
            'type' => 'MARKET',
            'quantity' => $orderQuantity,
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
        // Update the order with the computed entry data.
        $this->order->update([
            'entry_average_price' => $orderPrice,
            'entry_quantity' => $orderQuantity,
        ]);

        // Build the necessary API order data for a Limit order.
        $this->orderData = [
            'timeInForce' => 'GTC',
            'side' => strtoupper($sideDetails['orderSide']),
            'type' => 'LIMIT',
            'quantity' => $orderQuantity,
            'newClientOrderId' => 'EX:'.$this->exchangeSymbol->exchange->id.
                '-P:'.$this->order->position->id.
                '-O:'.$this->order->id,
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

    /**
     * Updates the order in the system with the result from the exchange.
     */
    private function updateOrderWithExchangeResult(array $result)
    {
        // Update order with the order system ID and result payload.
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
