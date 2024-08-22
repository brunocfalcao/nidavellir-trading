<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

class ExchangeSymbol extends AbstractModel
{
    protected $table = 'exchange_symbol';

    public function symbol()
    {
        return $this->belongsTo(Symbol::class);
    }

    public function exchange()
    {
        return $this->belongsTo(Exchange::class);
    }
}
