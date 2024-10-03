<?php

namespace Nidavellir\Trading\Observers;

use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\Jobs\System\RecalculateAvgWeightPrice;

class ExchangeSymbolObserver
{
    public function saving(ExchangeSymbol $model)
    {
        if ($model->isDirty('last_mark_price')) {
            $model->price_last_synced_at = now();
        }
    }

    public function updated(ExchangeSymbol $model)
    {
        // Check if the last_mark_price was updated.
        if ($model->isDirty('last_mark_price')) {
            // Fetch eligible positions that belong to this ExchangeSymbol.
            $eligiblePositionIds = Position::query()
                ->where('exchange_symbol_id', $model->id) // Positions tied to this ExchangeSymbol
                ->where('status', 'synced') // Only positions with status 'synced'
                ->where(function ($query) use ($model) {
                    // For SHORT positions: entry_average_price <= last_mark_price.
                    $query->where(function ($query) use ($model) {
                        $query->where('side', 'SHORT')
                              ->whereColumn('entry_average_price', '<=', $model->getAttribute('last_mark_price'));
                    })
                    // For LONG positions: entry_average_price >= last_mark_price.
                    ->orWhere(function ($query) use ($model) {
                        $query->where('side', 'LONG')
                              ->whereColumn('entry_average_price', '>=', $model->getAttribute('last_mark_price'));
                    });
                })
                ->distinct('id') // Ensure only unique position IDs are returned.
                ->pluck('id'); // Get only the position IDs.

            // Dispatch the RecalculateAvgWeightPrice job for each eligible position.
            foreach ($eligiblePositionIds as $positionId) {
                RecalculateAvgWeightPrice::dispatch($positionId);
            }
        }
    }

    public function deleted(ExchangeSymbol $model)
    {
        //
    }

    public function created(ExchangeSymbol $model)
    {
        //
    }
}
