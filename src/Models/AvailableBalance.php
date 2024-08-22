<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

class AvailableBalance extends AbstractModel
{
    public function trader()
    {
        return $this->belongsTo(Trader::class);
    }

    public function ExchangeSymbol()
    {
        return $this->belongsTo(ExchangeSymbol::class);
    }
}
