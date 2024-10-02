<?php

namespace Nidavellir\Trading\Jobs\System;

use Illuminate\Support\Facades\DB;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Models\Order;

/**
 * ScanLimitOrdersForPossibleFills manages the detection of possible
 * filled limit orders within the system and dispatches individual
 * jobs for each position to recalculate the weighted average price.
 *
 * - Filters limit orders that meet specific conditions for SHORT
 *   and LONG positions based on the mark price.
 * - Dispatches a job to recalculate the position's average price.
 */
class ScanLimitOrdersForPossibleFills extends AbstractJob
{
    // Constructor for initializing any specific properties.
    public function __construct()
    {
        // Initialization logic (if any) goes here.
    }

    /**
     * Executes the core logic for detecting filled orders and dispatching jobs for each position.
     */
    protected function compute()
    {
        // Fetch limit orders where both the order and position have status 'synced'.
        $possibleFilledOrders = Order::query()
            ->where('status', 'synced') // Only orders with status 'synced'
            ->where('type', 'LIMIT') // Only limit orders
            ->whereHas('position', function ($query) {
                $query->where('status', 'synced') // Only positions with status 'synced'
                    ->where(function ($query) {
                        // For SHORT positions: entry_average_price <= markPrice.
                        $query->where('side', 'SHORT')
                            ->whereColumn('orders.entry_average_price', '<=', 'positions.initial_mark_price');
                    })
                    ->orWhere(function ($query) {
                        // For LONG positions: entry_average_price >= markPrice.
                        $query->where('side', 'LONG')
                            ->whereColumn('orders.entry_average_price', '>=', 'positions.initial_mark_price');
                    });
            })
            ->get();

        // Iterate through each possible filled order.
        foreach ($possibleFilledOrders as $order) {
            // Apply a transaction to ensure atomicity.
            DB::transaction(function () use ($order) {
                // Lock the position for updates.
                $position = $order->position()->lockForUpdate()->first();

                if ($position) {
                    // Dispatch the RecalculatePositionAverageJob for the detected position.
                    RecalculatePositionAverageJob::dispatch($position->id);
                }
            });
        }
    }
}
