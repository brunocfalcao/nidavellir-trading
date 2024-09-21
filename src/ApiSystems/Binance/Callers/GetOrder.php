<?php

namespace Nidavellir\Trading\ApiSystems\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\ApiSystems\Binance\REST\Futures;

class GetOrder extends AbstractCaller
{
    protected string $callerName = 'Get Order';

    public function call()
    {
        $futures = new Futures($this->mapper->connectionDetails());
        $this->result = $futures->getOrder(
            $this->mapper->properties['options']['symbol'],
            $this->mapper->properties['options']
        );
    }
}
