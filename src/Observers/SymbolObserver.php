<?php

namespace Nidavellir\Trading\Observers;

use Nidavellir\Trading\Models\Symbol;

class SymbolObserver
{
    public function saving(Symbol $model)
    {
        if ($model->isDirty('last_mark_price')) {
            $this->price_last_synced_at = now();
        }
    }

    public function updated(Symbol $model)
    {
        //
    }

    public function deleted(Symbol $model)
    {
        //
    }

    public function created(Symbol $model)
    {
        //
    }
}
