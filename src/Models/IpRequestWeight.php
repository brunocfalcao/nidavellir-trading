<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

class IpRequestWeight extends AbstractModel
{
    protected $casts = [
        'last_reset_at' => 'datetime',
    ];

    public function exchange()
    {
        return $this->belongsTo(Exchange::class);
    }
}
