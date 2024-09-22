<?php

namespace Nidavellir\Trading\ApiSystems\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\ApiSystems\Binance\REST\Futures;

class UpdateMarginType extends AbstractCaller
{
    protected string $callerName = 'Update Margin Type';

    public function call()
    {
        $futures = new Futures($this->mapper->connectionDetails());
        $this->result = $futures->updateMarginType($this->mapper->properties);
    }
}
