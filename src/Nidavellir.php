<?php

namespace Nidavellir\Trading;

use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\Models\System;

class Nidavellir
{
    public static function getTradeConfiguration()
    {
        /**
         * The trade configuration returns the orders configuration
         * ready to be executed on the exchange. On this case it
         * will create configuration for the orders:
         * 1. The limit sell (at market) order.
         * 2. The market order.
         * 3. The N limit-buy orders
         *
         * Each order is composed of:
         * - Type (market, limit),
         * - Side (buy, sell)
         * - Percentage ratio (%)
         *
         * The percentage ratio is the "up or down" compared
         * to the market order. We don't compute the order
         * prices now, because there is a delay. So, we
         * compute the price % relation between orders.
         *
         * We also don't compute the amount. This will be
         * based on the actual portfolio amount on the
         * moment we are computing and placing the orders.
         *
         * The return is an order collection ready to be
         * queued, and attached to the position. The next
         * step is to process the order, and then compute
         * the price and amounts on-the-fly.
         *
         * REMARK: At the moment we just consider LONGS.
         */

        // Lets start by grabbing the fear and greed index.
        $system = System::all()->first();
        $fearAndGreedIndex = $system->fear_greed_index;

        // Detect if we are in bearshing or bullish sentiment.
        $marketTrend =
            $fearAndGreedIndex <= $system->fear_greed_index_threshold ?
            'bearish' : 'bullish';

        // Construct trade configuration.
        $tradeConfiguration['orders'] = config('nidavellir.orders.'.$marketTrend);
        $tradeConfiguration['positions'] = config('nidavellir.positions');

        return $tradeConfiguration;
    }
}
