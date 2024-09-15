<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

class IpRequestWeight extends AbstractModel
{
    public function exchange()
    {
        return $this->belongsTo(Exchange::class);
    }
}
