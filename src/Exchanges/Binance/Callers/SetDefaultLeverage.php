<?php

namespace Nidavellir\Trading\Exchanges\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\Exchanges\Binance\REST\Futures;

class SetDefaultLeverage extends AbstractCaller
{
    protected string $callerName = 'Set Default Leverage';

    public function call()
    {
        $futures = new Futures($this->mapper->connectionDetails());
        $this->result = $futures->setLeverage(
            $this->mapper->properties['options']
        );
    }
}
