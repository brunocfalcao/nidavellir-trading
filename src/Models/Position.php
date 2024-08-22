<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

class Position extends AbstractModel
{
    public function trader()
    {
        return $this->belongsTo(Trader::class);
    }
}
