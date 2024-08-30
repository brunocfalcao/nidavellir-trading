<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

class Position extends AbstractModel
{
    protected $casts = [
        'trade_configuration' => 'array',
    ];

    public function trader()
    {
        return $this->belongsTo(Trader::class);
    }
}
