<?php

namespace Nidavellir\Trading\Observers;

use Illuminate\Support\Facades\DB;
use Nidavellir\Trading\Jobs\Positions\ClosePositionJob;
use Nidavellir\Trading\Jobs\System\RecalculateAvgWeightPriceJob;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Position;

/**
 * ExchangeSymbolObserver handles various events on the
 * ExchangeSymbol model, including recalculating weighted
 * average prices and detecting profit achievement.
 */
class ExchangeSymbolObserver
{
    // Update price_last_synced_at timestamp when last_mark_price changes.
    public function saving(ExchangeSymbol $model)
    {
        if ($model->isDirty('last_mark_price')) {
            $model->price_last_synced_at = now();
        }
    }

    // Triggered when an ExchangeSymbol is updated, handles recalculation of orders.
    public function updated(ExchangeSymbol $model)
    {
        // Check if the last_mark_price was updated.
        if ($model->isDirty('last_mark_price')) {
            $lastMarkPrice = $model->getAttribute('last_mark_price');

            // Fetch eligible positions tied to this ExchangeSymbol.
            $eligiblePositionIds =
            DB::table('positions')
                ->select('positions.id')
                ->distinct()
                ->join('orders', 'positions.id', '=', 'orders.position_id')
                ->where('positions.exchange_symbol_id', $model->id) // Positions tied to this ExchangeSymbol.
                ->where('positions.status', 'synced') // Only synced positions.
                ->where('orders.status', 'synced') // Only synced orders.
                ->where('orders.type', 'LIMIT') // Limit orders only.
                ->whereRaw("
            IF(
                positions.side = 'SHORT',
                orders.entry_average_price <= ?,
                orders.entry_average_price >= ?
            )", [$lastMarkPrice, $lastMarkPrice]) // Apply condition based on position's side
                ->pluck('positions.id');

            // Dispatch RecalculateAvgWeightPriceJob for eligible positions.
            foreach ($eligiblePositionIds as $positionId) {
                RecalculateAvgWeightPriceJob::dispatch($positionId);
            }

            // Additional logic for detecting profit orders being achieved.
            $profitPositionIds =
            DB::table('positions')
                ->select('positions.id')
                ->distinct()
                ->join('orders', 'positions.id', '=', 'orders.position_id')
                ->where('positions.exchange_symbol_id', $model->id) // Positions tied to this ExchangeSymbol.
                ->where('positions.status', 'synced') // Only synced positions.
                ->where('orders.status', 'synced') // Only synced orders.
                ->where('orders.type', 'PROFIT') // Only profit orders.
                ->whereRaw("
            IF(
                positions.side = 'LONG',
                orders.entry_average_price <= ?,
                orders.entry_average_price >= ?
            )", [$lastMarkPrice, $lastMarkPrice]) // Apply condition based on position's side
                ->pluck('positions.id'); // Get only the position IDs

            // Dispatch ClosePositionJob for each position where profit was achieved.
            foreach ($profitPositionIds as $positionId) {
                \Log::info('Calling ClosePosition for position Id '.$positionId);
                ClosePositionJob::dispatch($positionId);
            }
        }
    }
}
