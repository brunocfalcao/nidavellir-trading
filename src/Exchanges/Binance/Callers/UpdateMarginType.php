<?php

namespace Nidavellir\Trading\Exchanges\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\Exchanges\Binance\REST\Futures;

class UpdateMarginType extends AbstractCaller
{
    protected string $callerName = 'Update Margin Type';

    public function call()
    {
        $futures = new Futures($this->mapper->connectionDetails());
        $this->result = $futures->updateMarginType(
            $this->mapper->properties['options']
        );
    }
}
