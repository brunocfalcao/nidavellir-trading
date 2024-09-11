<?php

namespace Nidavellir\Trading\Jobs\Orders;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\OrderNotSyncedException;
use Nidavellir\Trading\Models\ApplicationLog;
use Nidavellir\Trading\Models\Order;
use Throwable;

class DispatchOrderJob extends AbstractJob
{
    // Constants for different order types: Market, Limit, and Profit.
    public const ORDER_TYPE_MARKET = 'MARKET';

    public const ORDER_TYPE_LIMIT = 'LIMIT';

    public const ORDER_TYPE_PROFIT = 'PROFIT';

    public int $orderId;

    /**
     * Initializes the job with a specific order ID
     * that is used to retrieve the order for processing.
     */
    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }

    /**
     * Main function that handles the dispatching of the order.
     * It performs necessary checks and processes based on
     * the type of order and related sibling orders.
     */
    public function handle()
    {
        try {
            // Retrieve the order model from the database using the order ID.
            $order = Order::find($this->orderId);
            if (! $order) {
                // If the order does not exist, exit without further action.
                return;
            }

            ApplicationLog::withActionCanonical('order.dispatch')
                ->withDescription('Job started')
                ->withLoggable($order)
                ->saveLog();

            // Retrieve sibling orders associated with the same position,
            // excluding the current order. Filters for only limit orders.
            $siblings = $order->position->orders->where('id', '<>', $order->id);
            $siblingsLimitOnly = $siblings->where('type', self::ORDER_TYPE_LIMIT);

            // Retry logic: if attempts reach 3, throw an exception.
            if ($this->attempts() == 3) {
                $order->update(['status' => 'error']);
                throw new OrderNotSyncedException(
                    'Max attempts: Failed to create order on exchange, with ID: '.
                    $this->orderId,
                    ['order_id' => $this->orderId],
                    $order
                );

                return;
            }

            // If any sibling order has an error, stop further processing.
            if ($siblings->contains('status', 'error')) {
                return;
            }

            // Check conditions specific to Market or Profit orders before proceeding.
            if ($this->shouldWaitForLimitOrdersToFinish($order, $siblingsLimitOnly)) {
                return;
            }

            if ($this->shouldWaitForAllOrdersExceptProfit($order, $siblings)) {
                return;
            }

            // Process the order if all checks pass.
            $this->processOrder($order);
        } catch (Throwable $e) {
            $order->update(['status' => 'error']);

            // Handle any exceptions by throwing a custom OrderNotSyncedException.
            throw new OrderNotSyncedException(
                $e->getMessage(),
                ['order_id' => $this->orderId],
                $order
            );
        }
    }

    /**
     * Determines if the current Market order should wait for
     * all Limit orders to be processed before proceeding.
     */
    private function shouldWaitForLimitOrdersToFinish($order, $siblingsLimitOnly)
    {
        if ($order->type === self::ORDER_TYPE_MARKET && $siblingsLimitOnly->contains('status', 'new')) {
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
    private function shouldWaitForAllOrdersExceptProfit($order, $siblings)
    {
        if ($order->type === self::ORDER_TYPE_PROFIT && $siblings->contains('status', 'new')) {
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
    private function processOrder($order)
    {
        ApplicationLog::withActionCanonical('order.dispatch')
            ->withDescription('Process Order started')
            ->withLoggable($order)
            ->saveLog();

        // Determine the side of the order (buy/sell).
        $sideDetails = $this->getOrderSideDetails(config('nidavellir.positions.current_side'));

        ApplicationLog::withActionCanonical('order.dispatch')
            ->withDescription('Attribute $sideDetails')
            ->withReturnData($sideDetails)
            ->withLoggable($order)
            ->saveLog();

        // Build the payload for the API call.
        $payload = $order->position->trader
            ->withRESTApi()
            ->withPosition($order->position)
            ->withTrader($order->position->trader)
            ->withExchangeSymbol($order->position->exchangeSymbol)
            ->withOrder($order);

        ApplicationLog::withActionCanonical('order.dispatch')
            ->withDescription('Attribute $payload')
            ->withReturnData($payload)
            ->withLoggable($order)
            ->saveLog();

        // Calculate the price of the order based on its type and market conditions.
        $orderPrice = $this->getPriceByRatio($order);

        // Compute the amount of the asset to be traded in the order.
        $orderAmount = $this->computeOrderAmount($order, $orderPrice);

        // Log the order details for debugging purposes.
        $this->logOrderDetails($order, $orderAmount, $orderPrice);

        // Dispatch the order based on its type (Market, Limit, Profit).
        $this->dispatchOrder($order, $orderPrice, $orderAmount, $sideDetails);
    }

    /**
     * Computes the amount of the token to be traded based on
     * the total trade amount, leverage, and the price of the token.
     */
    private function computeOrderAmount($order, $price)
    {
        $exchangeSymbol = $order->position->exchangeSymbol;

        // Handles both MARKET and LIMIT order types.
        if (in_array($order->type, [self::ORDER_TYPE_MARKET, self::ORDER_TYPE_LIMIT])) {

            /**
             * Calculate the token amount to buy, factoring in
             * leverage and dividing by the configured price.
             */
            $amountAfterDivider = $order->position->total_trade_amount / $order->amount_divider;
            $amountAfterLeverage = $amountAfterDivider * $order->position->leverage;
            $tokenAmountToBuy = $amountAfterLeverage / $price;

            return round($tokenAmountToBuy, $exchangeSymbol->precision_quantity);
        }

        // For PROFIT or other types, return a hardcoded value.
        return 100;
    }

    /**
     * Dispatches the order to the exchange, adjusting the
     * logic based on whether it is a Market, Limit, or Profit order.
     */
    private function dispatchOrder($order, $orderPrice, $orderAmount, $sideDetails)
    {
        switch ($order->type) {
            case self::ORDER_TYPE_LIMIT:
                $this->placeLimitOrder($order, $orderPrice, $orderAmount, $sideDetails);
                break;
            case self::ORDER_TYPE_MARKET:
                $this->placeMarketOrder($order, $orderAmount, $sideDetails);
                break;
            case self::ORDER_TYPE_PROFIT:
                // Handle profit orders, if necessary.
                break;
        }
    }

    /**
     * Places a Market order on the exchange, constructing
     * the necessary payload and submitting it via API.
     */
    private function placeMarketOrder($order, $orderAmount, $sideDetails)
    {
        $orderData = [
            'side' => strtoupper($sideDetails['orderSide']),
            'type' => 'MARKET',
            'quantity' => $orderAmount,
            'symbol' => $order->position->exchangeSymbol->symbol->token.'USDT',
        ];

        // Dispatch the order via the trader's API.
        $data = $order->position->trader
            ->withRESTApi()
            ->withOptions($orderData)
            ->withPosition($order->position)
            ->withTrader($order->position->trader)
            ->withExchangeSymbol($order->position->exchangeSymbol)
            ->withOrder($order)
            ->placeSingleOrder();

        // Update the order status to indicate it has been synced with the exchange.
        $order->update(['status' => 'synced']);
    }

    /**
     * Places a Limit order on the exchange, constructing
     * the payload with price and quantity details.
     */
    private function placeLimitOrder($order, $orderPrice, $orderAmount, $sideDetails)
    {
        $orderData = [
            'timeInForce' => 'GTC',
            'side' => strtoupper($sideDetails['orderSide']),
            'type' => 'LIMIT',
            'quantity' => $orderAmount,
            'symbol' => $order->position->exchangeSymbol->symbol->token.'USDT',
            'price' => $orderPrice,
        ];

        // Dispatch the order via the trader's API.
        $result = $order->position->trader
            ->withRESTApi()
            ->withOptions($orderData)
            ->withPosition($order->position)
            ->withTrader($order->position->trader)
            ->withExchangeSymbol($order->position->exchangeSymbol)
            ->withOrder($order)
            ->placeSingleOrder();

        $this->updateOrderWithExchangeResult($order, $result);
    }

    private function updateOrderWithExchangeResult(Order $order, array $result)
    {
        // Update order with the order system id and result payload.
        $order->update([
            'api_order_id' => $result['orderId'],
            /* FUTURE TODO:
                                 $order->position
                                       ->trader
                                       ->withApiGetter() <----
                                       ->withOrder($order)
                                       ->getOrderId($result),
                                */
            'price' => $result['price'],
            /* FUTURE TODO:
                                 $order->position
                                       ->trader
                                       ->withApiGetter() <----
                                       ->withOrder($order)
                                       ->getPrice($result),
                                */
            'api_result' => $result,
            'status' => 'synced',
        ]);
    }

    /**
     * Logs details of the order for debugging purposes, such as
     * the order type, price, and amount.
     */
    private function logOrderDetails($order, $orderAmount, $orderPrice)
    {
        info_multiple(
            '=== ORDER ID '.$order->id,
            'Type: '.$order->type,
            'Token: '.$order->position->exchangeSymbol->symbol->token,
            'Total Trade Amount: '.$order->position->total_trade_amount,
            'Token Price: '.round($order->position->initial_mark_price, $order->position->exchangeSymbol->precision_price),
            'Amount Divider: '.$order->amount_divider,
            'Ratio: '.$order->price_ratio_percentage,
            'Order Price: '.$orderPrice,
            'Order amount: '.$orderAmount,
            'Order amount (USDT): '.$orderAmount * $orderPrice,
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
    private function getPriceByRatio(Order $order)
    {
        $markPrice = $order->position->initial_mark_price;
        $precision = $order->position->exchangeSymbol->precision_price;
        $priceRatio = $order->price_ratio_percentage / 100;
        $side = $order->position->side;

        $orderPrice = 0;

        // Determine the order price based on whether it's a Buy or Sell.
        if ($side === 'BUY') {
            $orderPrice = $order->type !== self::ORDER_TYPE_PROFIT
                ? round($markPrice - ($markPrice * $priceRatio), $precision)
                : round($markPrice + ($markPrice * $priceRatio), $precision);
        }

        if ($side === 'SELL') {
            $orderPrice = $order->type !== self::ORDER_TYPE_PROFIT
                ? round($markPrice + ($markPrice * $priceRatio), $precision)
                : round($markPrice - ($markPrice * $priceRatio), $precision);
        }

        /**
         * Adjust the computed price to the correct tick size
         * to ensure the order is not rejected by the exchange.
         */
        $priceTickSizeAdjusted = $this->adjustPriceToTickSize(
            $orderPrice,
            $order->position->exchangeSymbol->tick_size
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
