<?php

namespace Nidavellir\Trading\Jobs\Orders;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Models\Order;

class _DispatchOrderJob extends AbstractJob
{
    public int $orderId;

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }

    public function handle()
    {
        // Get this order model.
        $order = Order::find($this->orderId);

        // Get the other siblings.
        $siblings = $order->position->orders
            ->where('id', '<>', $order->id);

        $siblingsLimitOnly = $order->position->orders
            ->where('id', '<>', $order->id)
            ->where('type', 'LIMIT');

        // At least a sibling with error? Stop.
        if ($siblings->contains('status', 'error')) {
            return;
        }

        /**
         * Conditions for release.
         */

        /**
         * Market order and limit orders still being
         * processed, we will need to wait a bit.
         */
        if ($order->type == 'MARKET' &&
            $siblingsLimitOnly->contains('status', 'new')) {
            $this->release(5);

            return;
        }

        /**
         * Profit order and limit or market orders still being
         * processed, we will also need to wait a bit.
         */
        if ($order->type == 'PROFIT' && $siblings->contains('status', 'new')) {
            $this->release(5);
        }

        /**
         * All good! We can continue processing the order.
         */

        /**
         * Lets compute the LIMIT-{type} and the {side}.
         *
         * Depending if we are longing or shorting.
         */
        $side = config('nidavellir.positions.current_side');

        if ($side == 'BUY') {
            $orderSide = 'buy';
            $orderLimitBuy = 'buy';
            $orderLimitProfit = 'sell';
        } else {
            $orderSide = 'sell';
            $orderLimitBuy = 'sell';
            $orderLimitProfit = 'buy';
        }

        /**
         * Lets place the order and get that lambo closer.
         *
         * Order data:
         * Token
         * Side
         * Type
         * Price (if it's not market)
         * Quantity
         *
         * We need to look to the price percentage ratio and
         * to the amount divider for each order placement.
         */
        $payload = $order
            ->position
            ->trader
            ->withRESTApi()
            ->withPosition($order->position)
            ->withTrader($order->position->trader)
            ->withExchangeSymbol($order->position->exchangeSymbol)
            ->withOrder($order);

        /**
         * Obtain precisions for future price and quantity
         * calculations.
         */
        $exchangeSymbol = $order->position->exchangeSymbol;
        $precisionPrice = $exchangeSymbol->precision_price;
        $precisionQuantity = $exchangeSymbol->precision_quantity;

        /**
         * Compute quantity and price.
         */
        $markPrice = round($order->position->initial_mark_price, $precisionPrice);
        $tradeAmount = $order->position->total_trade_amount;

        /**
         * If it's a PROFIT order we will calculate it
         * differently later, since we need to fetch
         * all the quantities from all the orders.
         */
        if ($order->type == 'MARKET' || $order->type == 'LIMIT') {

            /**
             * Finally, calculate the order amount. The order
             * amount will be the USDT that we will actually
             * allocate to the order (but only 1/X from the
             * leverage that was selected).
             */
            $orderAmount =
                $this->adjustPriceToTickSize(
                    round(
                        $tradeAmount /
                        $order->amount_divider *
                        $order->position->leverage,
                        $exchangeSymbol->precision_quantity
                    ),
                    $exchangeSymbol->tick_size
                );
        } else {
            // Just hard coded for now.
            $orderAmount = 100;
        }

        /**
         * Compute the order price, given the respective
         * order percentage ratio and symbol tick size.
         */
        $orderPrice = round(
            $this->getPriceByRatio($order, $markPrice),
            $exchangeSymbol->precision_price
        );

        info_multiple(
            '=== ORDER ID '.$order->id,
            'Type: '.$order->type,
            'Token: '.$exchangeSymbol->symbol->token,
            'Total Trade Amount: '.$tradeAmount,
            'Token Price: '.$markPrice,
            'Amount Divider: '.$order->amount_divider,
            'Ratio: '.$order->price_ratio_percentage,
            'Order Price:'.$orderPrice,
            'Tick Size: '.$exchangeSymbol->tick_size,
            'Order amount: '.$orderAmount,
            '===',
            ''
        );

        switch ($order->type) {
            case 'LIMIT':
                $orderData = [
                    'side' => strtoupper($orderSide),
                    'type' => 'LIMIT',
                    'quantity' => $orderQuantity,
                    // TODO. Change the value to other exchanges.
                    'symbol' => $exchangeSymbol->symbol->token.'USDT',
                    'price' => $orderPrice,
                ];

                $order->position
                    ->trader
                    ->withRESTApi()
                    ->withOptions($orderData)
                    ->withPosition($order->position)
                    ->withTrader($order->position->trader)
                    ->withExchangeSymbol($exchangeSymbol)
                    ->withOrder($order)
                    ->placeSingleOrder();

                $order->update(['status' => 'synced']);

                break;

            case 'MARKET':
                break;

            case 'PROFIT':
                break;
        }
    }

    private function getPriceByRatio(Order $order, float $markPrice)
    {
        $precision = $order->position->exchangeSymbol->precision_price;

        if ($order->position->side == 'BUY') {
            if ($order->type != 'PROFIT') {
                return round($markPrice - ($markPrice * $order->price_ratio_percentage / 100), $precision);
            } else {
                return round($markPrice + ($markPrice * $order->price_ratio_percentage / 100), $precision);
            }
        }

        if ($order->position->side == 'SELL') {
            if ($order->type != 'PROFIT') {
                return round($markPrice + ($markPrice * $order->price_ratio_percentage / 100), $precision);
            } else {
                return round($markPrice - ($markPrice * $order->price_ratio_percentage / 100), $precision);
            }
        }
    }

    private function adjustPriceToTickSize($price, $tickSize)
    {
        return floor($price / $tickSize) * $tickSize;
    }
}
