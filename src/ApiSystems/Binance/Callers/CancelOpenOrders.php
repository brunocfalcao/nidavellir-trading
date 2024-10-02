<?php

namespace Nidavellir\Trading\ApiSystems\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\ApiSystems\Binance\REST\Futures;

class CancelOpenOrders extends AbstractCaller
{
    protected string $callerName = 'Cancel Open Orders';

    public function call()
    {
        $futures = new Futures($this->mapper->connectionDetails());
        $this->result = $futures->cancelOpenOrders($this->mapper->properties);
    }
}
