<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

class IpRequestWeight extends AbstractModel
{
    protected $casts = [
        'last_reset_at' => 'datetime',
        'is_backed_off' => 'boolean',
    ];

    public function exchange()
    {
        return $this->belongsTo(ApiSystem::class, 'api_system_id');
    }
}
