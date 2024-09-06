<?php

namespace Nidavellir\Trading\Exchanges\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\Exchanges\Binance\REST\Futures;

class GetExchangeInformation extends AbstractCaller
{
    protected string $callerName = 'Get Exchange Information';

    public function call()
    {
        $futures = new Futures($this->mapper->connectionDetails());
        $this->result = $futures->exchangeInfo($this->mapper->properties['options'])['symbols'];
    }
}
