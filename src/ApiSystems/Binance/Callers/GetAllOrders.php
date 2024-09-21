<?php

namespace Nidavellir\Trading\ApiSystems\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\ApiSystems\Binance\REST\Futures;

class GetAllOrders extends AbstractCaller
{
    protected string $callerName = 'Get All Orders';

    public function call()
    {
        $futures = new Futures($this->mapper->connectionDetails());
        $this->result = $futures->allOrders($this->mapper->properties['options']['symbol'], $this->mapper->properties['options']);
    }
}
