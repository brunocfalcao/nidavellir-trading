<?php

namespace Nidavellir\Trading\Listeners\Orders;

use Nidavellir\Trading\Abstracts\AbstractListener;
use Nidavellir\Trading\Events\Orders\OrderCreatedEvent;

class OrderCreatedListener extends AbstractListener
{
    public function handle(OrderCreatedEvent $event)
    {
        $order = $event->order;

        // Grab the token name.
        $exchangeSymbol = $order->position->exchangeSymbol;
        $symbol = $exchangeSymbol->symbol;

        // Get total position trade amount.
        $tradeAmount = $order->position->total_trade_amount;

        // Compute the right quantity.
        $orderQuantity = round(
            $tradeAmount / $order->amount_divider / $exchangeSymbol->last_mark_price,
            $exchangeSymbol->precision_quantity
        );

        /**
         * Compute order parameters.
         * For now, only for Binance.
         */
        $orderSymbol = "{$symbol->token}USDT";
        $orderType = $order->price_percentage_ratio == 0 ? 'MARKET' : 'LIMIT';

        // Amount divider = 1 then it's a limit-"sell" order.
        $orderSide = $order->amount_divider == 1 ? 'SELL' : 'BUY';

        info_multiple(
            'Position id: '.$order->position->id,
            'Trade total amount: '.$tradeAmount,
            'Ratio (Quantity division): '.$order->amount_divider, // Quantity ratio
            'Ratio (Percentage): '.$order->price_percentage_ratio,
            'Symbol: '.$symbol->token,
            'Mark Price: '.$exchangeSymbol->last_mark_price,
            'Quantity: '.$orderQuantity,
            'Type: '.$orderType,
            'Side: '.$orderSide
        );

        // Market order? Process immediately.
        if ($order->price_percentage_ratio == 0) {
            $order->position->trader
                ->withRESTApi()
                ->withOrder($order);
        }
    }
}
