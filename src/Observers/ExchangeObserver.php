<?php

namespace Nidavellir\Trading\Observers;

use Nidavellir\Trading\Models\Exchange;

class ExchangeObserver
{
    public function saving(Exchange $exchange)
    {
        $exchange->validate();
    }

    public function updated(Exchange $exchange)
    {
        //
    }

    public function deleted(Exchange $exchange)
    {
        //
    }

    public function created(Exchange $exchange)
    {
        //
    }
}
