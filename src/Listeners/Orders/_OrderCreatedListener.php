<?php

namespace Nidavellir\Trading\Listeners\Orders;

use Nidavellir\Trading\Abstracts\AbstractListener;
use Nidavellir\Trading\Events\Orders\OrderCreatedEvent;

class _OrderCreatedListener extends AbstractListener
{
    public function handle(OrderCreatedEvent $event)
    {
        $order = $event->order;

        // Grab the token name.
        $exchangeSymbol = $order->position->exchangeSymbol;
        $symbol = $exchangeSymbol->symbol;

        // Get total position trade amount.
        $tradeAmount = $order->position->total_trade_amount;

        /**
         * Compute order parameters.
         * For now, only for Binance.
         */
        $orderSymbol = "{$symbol->token}USDT";
        $orderType = $order->price_ratio_percentage == 0 ? 'MARKET' : 'LIMIT';

        /**
         * Market order?
         * Get the symbol last mark price.
         */
        if ($orderType == 'MARKET') {
            $markPrice = $order->position
                ->trader
                ->withRESTApi()
                ->withExchangeSymbol($exchangeSymbol)
                ->withPosition($order->position)
                ->withOrder($order)
                ->withSymbol($orderSymbol)
                ->getMarkPrice();

            $exchangeSymbol->update(['last_mark_price' => $markPrice]);
        } else {
            /**
             * A limit order will have the price computed
             * by the mark price of the market order, and
             * then computed using the percentage ratio.
             */
            return;
        }

        // Compute the right quantity.
        $orderQuantity = round(
            $tradeAmount / $order->amount_divider / $markPrice,
            $exchangeSymbol->precision_quantity
        );

        // Amount divider = 1 then it's a limit-"sell" order.
        $orderSide = $order->amount_divider == 1 ? 'SELL' : 'BUY';

        info_multiple(
            ' ',
            '======= TRADE START =======',
            'Position id ------------- : '.$order->position->id,
            'Trade total amount ------ : '.$tradeAmount,
            'Ratio (Quantity division) : '.$order->amount_divider, // Quantity ratio
            'Ratio (Percentage) ------ : '.$order->price_ratio_percentage,
            'Symbol ------------------ : '.$symbol->token,
            'Mark Price -------------- : '.$markPrice,
            'Quantity ---------------- : '.$orderQuantity,
            'Type -------------------- : '.$orderType,
            'Side -------------------- : '.$orderSide,
            '======== TRADE END =======',
            ' '
        );

        // Fill the mapper properties with the concluded data.
        $orderData = [
            'side' => $orderSide,
            'type' => $orderType,
            'quantity' => $orderQuantity,
            'symbol' => $symbol->token.'USDT',
            //'price' => $
        ];

        // Market order? Process immediately.
        /*
        if ($order->price_ratio_percentage == 0) {
            $order->position->trader
                ->withRESTApi()
                ->withOptions($orderData)
                ->withPosition($order->position)
                ->withTrader($order->position->trader)
                ->withExchangeSymbol($exchangeSymbol)
                ->withOrder($order)
                ->placeSingleOrder();
        }
        */
    }
}
