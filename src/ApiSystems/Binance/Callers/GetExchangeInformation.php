<?php

namespace Nidavellir\Trading\ApiSystems\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\ApiSystems\Binance\REST\Futures;

class GetExchangeInformation extends AbstractCaller
{
    protected string $callerName = 'Get Exchange Information';

    public function call()
    {
        $futures = new Futures($this->mapper->connectionDetails());
        $this->result = $futures->exchangeInfo($this->mapper->properties['options'])['symbols'];
    }
}
