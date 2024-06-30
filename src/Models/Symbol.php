<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

class Symbol extends AbstractModel
{
    public function exchanges()
    {
        return $this->belongsToMany(Exchange::class);
    }
}
