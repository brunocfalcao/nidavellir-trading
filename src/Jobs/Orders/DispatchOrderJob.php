<?php

namespace Nidavellir\Trading\Jobs\Orders;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\OrderNotCreatedException;
use Nidavellir\Trading\Models\Order;
use Throwable;

class DispatchOrderJob extends AbstractJob
{
    public const ORDER_TYPE_MARKET = 'MARKET';

    public const ORDER_TYPE_LIMIT = 'LIMIT';

    public const ORDER_TYPE_PROFIT = 'PROFIT';

    public int $orderId;

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }

    public function handle()
    {
        try {
            // Retrieve order model.
            $order = Order::find($this->orderId);
            if (! $order) {
                // Log or throw if the order does not exist.
                return;
            }

            // Get siblings orders except the current one.
            $siblings = $order->position->orders->where('id', '<>', $order->id);
            $siblingsLimitOnly = $siblings->where('type', self::ORDER_TYPE_LIMIT);

            if ($this->attempts() == 3) {
                throw new OrderNotCreatedException(
                    'Max attemps: Failed to create order on exchange, with ID: '.
                    $this->orderId,
                    $this->orderId,
                    0,
                    $e
                );

                return;
            }

            // Check if any sibling has an error, if so, stop processing.
            if ($siblings->contains('status', 'error')) {
                return;
            }

            // Handle different conditions for market and profit orders.
            if ($this->shouldWaitForMarketOrder($order, $siblingsLimitOnly)) {
                return;
            }

            if ($this->shouldWaitForProfitOrder($order, $siblings)) {
                return;
            }

            // Continue processing the order.
            $this->processOrder($order);
        } catch (Throwable $e) {
            // Throw your custom exception, passing the order ID.
            throw new OrderNotCreatedException('Failed to create order on exchange, with ID: '.$this->orderId, $this->orderId, 0, $e);
        }
    }

    private function shouldWaitForMarketOrder($order, $siblingsLimitOnly)
    {
        if ($order->type === self::ORDER_TYPE_MARKET && $siblingsLimitOnly->contains('status', 'new')) {
            $this->release(5);

            return true;
        }

        return false;
    }

    private function shouldWaitForProfitOrder($order, $siblings)
    {
        if ($order->type === self::ORDER_TYPE_PROFIT && $siblings->contains('status', 'new')) {
            $this->release(5);

            return true;
        }

        return false;
    }

    private function processOrder($order)
    {
        $sideDetails = $this->getOrderSideDetails(config('nidavellir.positions.current_side'));

        // Build the payload for order processing.
        $payload = $order->position->trader
            ->withRESTApi()
            ->withPosition($order->position)
            ->withTrader($order->position->trader)
            ->withExchangeSymbol($order->position->exchangeSymbol)
            ->withOrder($order);

        $orderPrice = $this->getPriceByRatio($order);

        $orderAmount = $this->computeOrderAmount($order, $orderPrice);

        // Log order details for debugging.
        $this->logOrderDetails($order, $orderAmount, $orderPrice);

        return;

        // Place the order depending on its type.
        $this->dispatchOrder($order, $orderPrice, $orderAmount, $sideDetails);
    }

    private function computeOrderAmount($order, $price)
    {
        $exchangeSymbol = $order->position->exchangeSymbol;

        // If the order is a MARKET or LIMIT order.
        if (in_array($order->type, [self::ORDER_TYPE_MARKET, self::ORDER_TYPE_LIMIT])) {

            /**
             * The amount to buy is a real amount of token that is then
             * mutiplied by the price that is configured for this order.
             */
            $amountAfterDivider = $order->position->total_trade_amount / $order->amount_divider;
            $amountAfterLeverage = $amountAfterDivider * $order->position->leverage;
            $tokenAmountToBuy = $amountAfterLeverage / $price;

            return
                round(
                    $tokenAmountToBuy,
                    $exchangeSymbol->precision_quantity
                );
        }

        // For PROFIT or other types, return a hardcoded value (can be refactored later).
        return 100;
    }

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
                // Handle profit order here if needed.
                break;
        }
    }

    private function placeMarketOrder($order, $orderAmount, $sideDetails)
    {
        $orderData = [
            'side' => strtoupper($sideDetails['orderSide']),
            'type' => 'MARKET',
            'quantity' => $orderAmount,
            'symbol' => $order->position->exchangeSymbol->symbol->token.'USDT',
        ];

        $order->position->trader
            ->withRESTApi()
            ->withOptions($orderData)
            ->withPosition($order->position)
            ->withTrader($order->position->trader)
            ->withExchangeSymbol($order->position->exchangeSymbol)
            ->withOrder($order)
            ->placeSingleOrder();

        $order->update(['status' => 'synced']);
    }

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

        $order->position->trader
            ->withRESTApi()
            ->withOptions($orderData)
            ->withPosition($order->position)
            ->withTrader($order->position->trader)
            ->withExchangeSymbol($order->position->exchangeSymbol)
            ->withOrder($order)
            ->placeSingleOrder();

        $order->update(['status' => 'synced']);
    }

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

    private function getPriceByRatio(Order $order)
    {
        $markPrice = $order->position->initial_mark_price;
        $precision = $order->position->exchangeSymbol->precision_price;
        $priceRatio = $order->price_ratio_percentage / 100;
        $side = $order->position->side;

        $orderPrice = 0;

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
         * Adjust the computed price the correct price tick
         * size, so the order doesn't get rejected.
         */
        $priceTickSizeAdjusted = $this->adjustPriceToTickSize(
            $orderPrice,
            $order->position->exchangeSymbol->tick_size
        );

        return $priceTickSizeAdjusted;
    }

    private function adjustPriceToTickSize($price, $tickSize)
    {
        return floor($price / $tickSize) * $tickSize;
    }
}
