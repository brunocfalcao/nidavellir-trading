<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

class ExchangeSymbol extends AbstractModel
{
    protected $casts = [
        'api_data' => 'array',
    ];

    public function symbol()
    {
        return $this->belongsTo(Symbol::class);
    }

    public function exchange()
    {
        return $this->belongsTo(Exchange::class);
    }
}
