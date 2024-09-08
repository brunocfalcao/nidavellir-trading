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
            throw new OrderNotCreatedException('Failed to create market order for order ID: '.$this->orderId, $this->orderId, 0, $e);
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

        $orderAmount = $this->computeOrderAmount($order);
        $orderPrice = $this->computeOrderPrice($order);

        // Log order details for debugging.
        $this->logOrderDetails($order, $orderAmount, $orderPrice);

        // Place the order depending on its type.
        $this->dispatchOrder($order, $orderPrice, $orderAmount, $sideDetails);
    }

    private function computeOrderAmount($order)
    {
        $exchangeSymbol = $order->position->exchangeSymbol;

        // If the order is a MARKET or LIMIT order.
        if (in_array($order->type, [self::ORDER_TYPE_MARKET, self::ORDER_TYPE_LIMIT])) {
            return $this->adjustPriceToTickSize(
                round(
                    $order->position->total_trade_amount / $order->amount_divider * $order->position->leverage,
                    $exchangeSymbol->precision_quantity
                ),
                $exchangeSymbol->tick_size
            );
        }

        // For PROFIT or other types, return a hardcoded value (can be refactored later).
        return 100;
    }

    private function computeOrderPrice($order)
    {
        $exchangeSymbol = $order->position->exchangeSymbol;
        $markPrice = round($order->position->initial_mark_price, $exchangeSymbol->precision_price);

        return round($this->getPriceByRatio($order, $markPrice), $exchangeSymbol->precision_price);
    }

    private function dispatchOrder($order, $orderPrice, $orderAmount, $sideDetails)
    {
        switch ($order->type) {
            case self::ORDER_TYPE_LIMIT:
                $this->placeLimitOrder($order, $orderPrice, $orderAmount, $sideDetails);
                break;
            case self::ORDER_TYPE_MARKET:
                // Handle market order here if needed.
                break;
            case self::ORDER_TYPE_PROFIT:
                // Handle profit order here if needed.
                break;
        }
    }

    private function placeLimitOrder($order, $orderPrice, $orderAmount, $sideDetails)
    {
        $orderData = [
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
            '==='
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

    private function getPriceByRatio(Order $order, float $markPrice)
    {
        $precision = $order->position->exchangeSymbol->precision_price;
        $priceRatio = $order->price_ratio_percentage / 100;
        $side = $order->position->side;

        if ($side === 'BUY') {
            return $order->type !== self::ORDER_TYPE_PROFIT
                ? round($markPrice - ($markPrice * $priceRatio), $precision)
                : round($markPrice + ($markPrice * $priceRatio), $precision);
        }

        if ($side === 'SELL') {
            return $order->type !== self::ORDER_TYPE_PROFIT
                ? round($markPrice + ($markPrice * $priceRatio), $precision)
                : round($markPrice - ($markPrice * $priceRatio), $precision);
        }
    }

    private function adjustPriceToTickSize($price, $tickSize)
    {
        return floor($price / $tickSize) * $tickSize;
    }
}
