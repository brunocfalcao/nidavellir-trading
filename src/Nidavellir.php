<?php

namespace Nidavellir\Trading;

use Nidavellir\Trading\Models\System;

/**
 * Nidavellir class is responsible for handling trading
 * configurations, calculating maximum leverage, and
 * retrieving system credentials.
 */
class Nidavellir
{
    /**
     * This method calculates the maximum leverage that can
     * be used for a trade based on the binance
     * NotionalandBracket leverage data. In the future,
     * this will change to universal data for multiple
     * exchanges.
     */
    public static function getMaximumLeverage(array $data, string $symbol, float $totalTradeAmount): int
    {
        if ($data['symbol'] === $symbol) {
            $maxLeverage = 1; // Default to the minimum leverage.

            // Iterate over the leverage brackets for the symbol.
            foreach ($data['brackets'] as $bracket) {
                $potentialTradeAmount = $totalTradeAmount * $bracket['initialLeverage'];

                // Check if the potential trade amount does not exceed the notionalCap.
                if ($potentialTradeAmount <= $bracket['notionalCap'] && $bracket['initialLeverage'] > $maxLeverage) {
                    $maxLeverage = $bracket['initialLeverage'];
                }
            }

            return $maxLeverage;
        }
    }

    /**
     * This method retrieves system credentials for the
     * given exchange configuration.
     */
    public static function getSystemCredentials(string $exchangeConfigCanonical)
    {
        return config('nidavellir.system.api.credentials.'.$exchangeConfigCanonical);
    }

    /**
     * This method returns the trade configuration, which
     * includes the order configuration ready to be
     * executed on the exchange. It configures market
     * and limit orders based on market trends and the
     * fear and greed index.
     *
     * - The configuration includes:
     *   1. A limit sell order.
     *   2. A market order.
     *   3. Multiple limit-buy orders.
     *
     * - Each order configuration contains:
     *   - Type (market, limit)
     *   - Side (buy, sell)
     *   - Percentage ratio relative to the market order
     *
     * - The prices and amounts are computed on-the-fly
     *   when the actual orders are placed.
     *
     * - Only LONG positions are considered for now.
     */
    public static function getTradeConfiguration()
    {
        // Start by grabbing the fear and greed index.
        $system = System::all()->first();

        // Construct the trade configuration based on the market sentiment.
        $ordersGroup = config('nidavellir.positions.current_order_ratio_group');
        $tradeConfiguration['orders'] = config('nidavellir.orders.'.$ordersGroup);
        $tradeConfiguration['positions'] = config('nidavellir.positions');

        return $tradeConfiguration;
    }
}
