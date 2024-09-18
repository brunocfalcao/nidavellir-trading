<?php

namespace Nidavellir\Trading\Jobs\Orders;

use Illuminate\Support\Str;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Models\ApplicationLog;
use Nidavellir\Trading\Models\Exchange;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Order;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Models\Trader;
use Nidavellir\Trading\NidavellirException;
use Throwable;

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

    // Holds the order being dispatched.
    public Order $order;

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

    // Log block for grouping log entries.
    private $logBlock;

    /**
     * Initializes the job with the specific order ID.
     * Preloads necessary entities to avoid repeated queries.
     */
    public function __construct(int $orderId)
    {
        // Retrieve the order and related entities.
        $this->order = Order::find($orderId);
        $this->trader = $this->order->position->trader;
        $this->position = $this->order->position;
        $this->exchangeSymbol = $this->order->position->exchangeSymbol;
        $this->symbol = $this->exchangeSymbol->symbol;
        $this->exchange = $this->order->position->trader->exchange;

        // Generate a UUID block for log entries.
        $this->logBlock = Str::uuid();
    }

    /**
     * Main function to handle the dispatching of the order.
     * It checks the type of order, manages retries, and processes
     * sibling orders before dispatching the current one.
     */
    public function handle()
    {
        // Log the start of the order dispatch process.
        ApplicationLog::withActionCanonical('order.dispatch.start')
            ->withDescription('Job started')
            ->withOrderId($this->order->id)
            ->withBlock($this->logBlock)
            ->saveLog();

        try {
            // Retrieve sibling orders excluding the current one.
            $siblings = $this->position->orders->where('id', '<>', $this->order->id);
            $siblingsLimitOnly = $siblings->where('type', self::ORDER_TYPE_LIMIT);

            // Handle retries and stop after 3 attempts.
            if ($this->attempts() == 3) {
                $this->order->update(['status' => 'error']);

                // Log the maximum attempts reached.
                ApplicationLog::withActionCanonical('order.dispatch.max_attempts')
                    ->withDescription('Max attempts reached for order dispatching')
                    ->withReturnData(['order_id' => $this->order->id])
                    ->withOrderId($this->order->id)
                    ->withBlock($this->logBlock)
                    ->saveLog();

                throw new NidavellirException(
                    title: 'Max attempts: Failed to create order on exchange',
                    additionalData: ['order_id' => $this->order->id],
                    loggable: $this->order
                );
            }

            // Stop processing if any sibling orders have errors.
            if ($siblings->contains('status', 'error')) {
                // Log that sibling orders have errors.
                ApplicationLog::withActionCanonical('order.dispatch.sibling_error')
                    ->withDescription('Sibling orders have errors, stopping dispatch')
                    ->withOrderId($this->order->id)
                    ->withBlock($this->logBlock)
                    ->saveLog();

                return;
            }

            // For Market orders, wait for all Limit orders to finish processing.
            if ($this->shouldWaitForLimitOrdersToFinish($siblingsLimitOnly)) {
                // Log that we're waiting for Limit orders to finish.
                ApplicationLog::withActionCanonical('order.dispatch.waiting_limit_orders')
                    ->withDescription('Waiting for Limit orders to finish before dispatching Market order')
                    ->withOrderId($this->order->id)
                    ->withBlock($this->logBlock)
                    ->saveLog();

                return;
            }

            // For Profit orders, wait for other orders to finish.
            if ($this->shouldWaitForAllOrdersExceptProfit($siblings)) {
                // Log that we're waiting for other orders to finish.
                ApplicationLog::withActionCanonical('order.dispatch.waiting_other_orders')
                    ->withDescription('Waiting for other orders to finish before dispatching Profit order')
                    ->withOrderId($this->order->id)
                    ->withBlock($this->logBlock)
                    ->saveLog();

                return;
            }

            // Proceed to process the order.
            $this->processOrder();
        } catch (Throwable $e) {
            // Log the error that occurred during order dispatching.
            ApplicationLog::withActionCanonical('order.dispatch.error')
                ->withDescription('Error occurred during order dispatching')
                ->withReturnData(['error' => $e->getMessage()])
                ->withOrderId($this->order->id)
                ->withBlock($this->logBlock)
                ->saveLog();

            // Update order to error.
            $this->order->update(['status' => 'error']);

            // Handle any errors and throw a custom exception.
            throw new NidavellirException(
                originalException: $e,
                title: 'Error occurred during order dispatching',
                loggable: $this->order
            );
        }
    }

    /**
     * Determines if the Market order should wait for all
     * Limit orders to finish processing.
     */
    private function shouldWaitForLimitOrdersToFinish($siblingsLimitOnly)
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
    private function shouldWaitForAllOrdersExceptProfit($siblings)
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
    private function processOrder()
    {
        // Log the start of the order processing.
        ApplicationLog::withActionCanonical('order.dispatch.process_order_start')
            ->withDescription('Process Order started')
            ->withOrderId($this->order->id)
            ->withBlock($this->logBlock)
            ->saveLog();

        // Get the side of the order (buy/sell).
        $sideDetails = $this->getOrderSideDetails(
            config('nidavellir.positions.current_side')
        );

        // Log the side details of the order.
        ApplicationLog::withActionCanonical('order.dispatch.side_details')
            ->withDescription('Attribute $sideDetails')
            ->withReturnData($sideDetails)
            ->withOrderId($this->order->id)
            ->withBlock($this->logBlock)
            ->saveLog();

        // Build the payload for dispatching the order.
        $payload = $this->trader
            ->withRESTApi()
            ->withPosition($this->position)
            ->withTrader($this->trader)
            ->withExchangeSymbol($this->exchangeSymbol)
            ->withOrder($this->order);

        // Log the payload details for debugging.
        ApplicationLog::withActionCanonical('order.dispatch.payload')
            ->withDescription('Attribute $payload')
            ->withReturnData($payload)
            ->withOrderId($this->order->id)
            ->withBlock($this->logBlock)
            ->saveLog();

        // Determine the price and quantity for the order.
        $orderPrice = $this->getPriceByRatio($this->position->initial_mark_price);
        $orderQuantity = $this->computeOrderAmount($orderPrice);

        // Log the order details (price, quantity) for debugging.
        $this->logOrderDetails($orderQuantity, $orderPrice);

        // Dispatch the order to the exchange.
        $this->dispatchOrder($orderPrice, $orderQuantity, $sideDetails);
    }

    /**
     * Computes the amount of the token to be traded based on
     * total trade amount, leverage, and the price of the token.
     */
    private function computeOrderAmount($price)
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
     * Places a Market order on the exchange.
     */
    private function placeMarketOrder($orderQuantity, $sideDetails)
    {
        // Build the API order data for a Market order.
        $this->orderData = [
            'newClientOrderId' => 'T:'.$this->trader->id.
                '-E:'.$this->exchange->id.
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

        // Update the order with the result from the exchange.
        $this->updateOrderWithExchangeResult($result);

        // Log the successful placement of the Market order.
        ApplicationLog::withActionCanonical('order.dispatch.market_order_placed')
            ->withDescription('Market order placed successfully')
            ->withReturnData($result)
            ->withOrderId($this->order->id)
            ->withBlock($this->logBlock)
            ->saveLog();
    }

    /**
     * Places a Limit order on the exchange.
     */
    private function placeLimitOrder($orderPrice, $orderQuantity, $sideDetails)
    {
        // Update the order with the computed entry data.
        $this->order->update([
            'entry_average_price' => $orderPrice,
            'entry_quantity' => $orderQuantity,
        ]);

        // Build the API order data for a Limit order.
        $this->orderData = [
            'timeInForce' => 'GTC',
            'side' => strtoupper($sideDetails['orderSide']),
            'type' => 'LIMIT',
            'quantity' => $orderQuantity,
            'newClientOrderId' => 'T:'.$this->trader->id.
                '-E:'.$this->exchange->id.
                '-P:'.$this->position->id.
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

        // Update the order with the result from the exchange.
        $this->updateOrderWithExchangeResult($result);

        // Log the successful placement of the Limit order.
        ApplicationLog::withActionCanonical('order.dispatch.limit_order_placed')
            ->withDescription('Limit order placed successfully')
            ->withReturnData($result)
            ->withOrderId($this->order->id)
            ->withBlock($this->logBlock)
            ->saveLog();
    }

    /**
     * Places a Profit order on the exchange.
     * Note: Implement this method as per your requirements.
     */
    private function placeProfitOrder($orderQuantity, $sideDetails)
    {
        // Implement the logic for placing a Profit order.
        // Add appropriate logging similar to other order types.
    }

    /**
     * Updates the order in the system with the result
     * from the exchange.
     */
    private function updateOrderWithExchangeResult(array $result)
    {
        // Update the order details with exchange result.
        $this->order->update([
            'order_exchange_system_id' => $result['orderId'],
            'filled_quantity' => $result['executedQty'],
            'filled_average_price' => $result['avgPrice'],
            'api_result' => $result,
            'status' => 'synced',
        ]);

        // Log the order update with exchange result.
        ApplicationLog::withActionCanonical('order.dispatch.order_updated')
            ->withDescription('Order updated with exchange result')
            ->withReturnData($result)
            ->withOrderId($this->order->id)
            ->withBlock($this->logBlock)
            ->saveLog();
    }

    /**
     * Logs the order details for debugging, including price
     * and amount of the order.
     */
    private function logOrderDetails($orderQuantity, $orderPrice)
    {
        $details = [
            'Order ID' => $this->order->id,
            'Type' => $this->order->type,
            'Token' => $this->symbol->token,
            'Total Trade Amount' => $this->position->total_trade_amount,
            'Token Price' => round($this->position->initial_mark_price, $this->exchangeSymbol->precision_price),
            'Amount Divider' => $this->order->amount_divider,
            'Ratio' => $this->order->price_ratio_percentage,
            'Order Price' => $orderPrice,
            'Order Quantity' => $orderQuantity,
            'Order Amount (USDT)' => $orderQuantity * $orderPrice,
        ];

        // Log the order details.
        ApplicationLog::withActionCanonical('order.dispatch.order_details')
            ->withDescription('Order details logged')
            ->withReturnData($details)
            ->withOrderId($this->order->id)
            ->withBlock($this->logBlock)
            ->saveLog();

        // Optionally, you can keep the original info_multiple logging if needed.
        info_multiple(
            '=== ORDER ID '.$this->order->id,
            'Type: '.$this->order->type,
            'Token: '.$this->symbol->token,
            'Total Trade Amount: '.$this->position->total_trade_amount,
            'Token Price: '.round($this->position->initial_mark_price, $this->exchangeSymbol->precision_price),
            'Amount Divider: '.$this->order->amount_divider,
            'Ratio: '.$this->order->price_ratio_percentage,
            'Order Price: '.$orderPrice,
            'Order Quantity: '.$orderQuantity,
            'Order Amount (USDT): '.($orderQuantity * $orderPrice),
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

        // Adjust the computed price to the correct tick size.
        return $this->adjustPriceToTickSize($orderPrice, $this->exchangeSymbol->tick_size);
    }

    /**
     * Adjusts the order price to match the exchange's tick size.
     */
    private function adjustPriceToTickSize($price, $tickSize)
    {
        return floor($price / $tickSize) * $tickSize;
    }
}
