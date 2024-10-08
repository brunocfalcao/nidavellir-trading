<?php

namespace Nidavellir\Trading\ApiSystems\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\ApiSystems\Binance\REST\Futures;

class SetDefaultLeverage extends AbstractCaller
{
    protected string $callerName = 'Set Default Leverage';

    public function call()
    {
        $futures = new Futures($this->mapper->connectionDetails());
        $this->result = $futures->setLeverage($this->mapper->properties);
    }
}
