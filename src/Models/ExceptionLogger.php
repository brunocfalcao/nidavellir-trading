<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

class ExceptionLogger extends AbstractModel
{
    public function loggable()
    {
        return $this->morphTo();
    }
}
