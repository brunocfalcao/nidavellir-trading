<?php

namespace Nidavellir\Trading\Observers;

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
        //
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
