<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

class EndpointWeight extends AbstractModel
{
    public function exchange()
    {
        return $this->belongsTo(ApiSystem::class, 'api_system_id');
    }
}
