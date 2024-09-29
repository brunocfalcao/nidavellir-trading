<?php

namespace Nidavellir\Trading\ApiSystems\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\ApiSystems\Binance\REST\Futures;

class CancelOrder extends AbstractCaller
{
    protected string $callerName = 'Cancel Order';

    public function call()
    {
        $futures = new Futures($this->mapper->connectionDetails());
        $this->result = $futures->cancelOrder($this->mapper->properties);
    }
}
