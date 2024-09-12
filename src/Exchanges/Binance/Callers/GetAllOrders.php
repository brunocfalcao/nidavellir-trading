<?php

namespace Nidavellir\Trading\Exchanges\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\Exchanges\Binance\REST\Futures;

class GetAllOrders extends AbstractCaller
{
    protected string $callerName = 'Get All Orders';

    public function call()
    {
        $futures = new Futures($this->mapper->connectionDetails());
        $this->result = $futures->allOrders($this->mapper->properties['options']['symbol'], $this->mapper->properties['options']);
    }
}
