<?php

namespace Nidavellir\Trading\Jobs\System;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Models\Order;

/**
 * ScanLimitOrdersForPossibleFills detects possible filled limit orders and dispatches
 * individual jobs to recalculate the average price for each position.
 */
class ScanLimitOrdersForPossibleFills extends AbstractJob
{
    /**
     * Executes the core logic for detecting filled orders and dispatching jobs for each position.
     */
    protected function compute()
    {

        // Fetch unique position IDs where their orders meet the conditions.
        $eligiblePositionIds = Order::query()
            ->where('status', 'synced') // Only orders with status 'synced'
            ->where('type', 'LIMIT') // Only limit orders
            ->whereHas('position', function ($query) {
                $query->where('status', 'synced') // Only positions with status 'synced'
                    ->whereHas('exchangeSymbol', function ($query) {
                        // Check conditions based on position side and order prices.
                        $query->where(function ($query) {
                            // For SHORT positions: entry_average_price <= last_mark_price.
                            $query->where('positions.side', 'SHORT')
                                ->whereColumn('orders.entry_average_price', '<=', 'exchange_symbols.last_mark_price');
                        })
                            ->orWhere(function ($query) {
                                // For LONG positions: entry_average_price >= last_mark_price.
                                $query->where('positions.side', 'LONG')
                                    ->whereColumn('orders.entry_average_price', '>=', 'exchange_symbols.last_mark_price');
                            });
                    });
            })
            ->distinct('position_id') // Ensure only unique position IDs are returned.
            ->pluck('position_id'); // Get only the position IDs.

        \Log::info('Possible positions: '.count($eligiblePositionIds));

        // Iterate through each possible filled order.
        foreach ($eligiblePositionIds as $positionId) {
            // Dispatch the RecalculateAvgWeightPrice for the detected position.
            RecalculateAvgWeightPrice::dispatchSync($positionId);
        }
    }
}
