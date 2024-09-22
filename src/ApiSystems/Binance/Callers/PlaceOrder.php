<?php

namespace Nidavellir\Trading\ApiSystems\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\ApiSystems\Binance\REST\Futures;

class PlaceOrder extends AbstractCaller
{
    protected string $callerName = 'New Order';

    public function call()
    {
        $futures = new Futures($this->mapper->connectionDetails());
        $this->result = $futures->newOrder($this->mapper->properties);
    }
}
