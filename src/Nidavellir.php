<?php

namespace Nidavellir\Trading;

use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\Models\System;

class Nidavellir
{
    /**
     * At the moment, the leverageData is exactly the
     * binance NotionalandBracket leverage token data.
     *
     * Later needs to change to an universal data when
     * we have more exchanges.
     */
    public static function getMaximumLeverage(array $data, string $symbol, float $totalTradeAmount): int
    {
        if ($data['symbol'] === $symbol) {
            $maxLeverage = 1; // Default to the minimum leverage

            foreach ($data['brackets'] as $bracket) {
                $potentialTradeAmount = $totalTradeAmount * $bracket['initialLeverage'];

                // Check if the potential trade amount does not exceed the notionalCap
                if ($potentialTradeAmount <= $bracket['notionalCap'] && $bracket['initialLeverage'] > $maxLeverage) {
                    $maxLeverage = $bracket['initialLeverage'];
                }
            }

            return $maxLeverage;
        }
    }

    public static function getSystemCredentials(string $exchangeConfigCanonical)
    {
        return config('nidavellir.system.api.credentials.'.$exchangeConfigCanonical);
    }

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
        $ordersGroup = config('nidavellir.positions.current_order_ratio_group');
        $tradeConfiguration['orders'] = config('nidavellir.orders.'.$ordersGroup);
        $tradeConfiguration['positions'] = config('nidavellir.positions');

        return $tradeConfiguration;
    }
}
