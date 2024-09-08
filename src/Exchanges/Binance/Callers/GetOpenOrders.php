<?php

namespace Nidavellir\Trading\Exchanges\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\Exchanges\Binance\REST\Futures;

class GetOpenOrders extends AbstractCaller
{
    protected string $callerName = 'Get Open Orders';

    public function call()
    {
        $futures = new Futures($this->mapper->connectionDetails());
        $this->result = $futures->openOrders($this->mapper->properties['options']);
    }
}
