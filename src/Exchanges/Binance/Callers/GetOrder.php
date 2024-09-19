<?php

namespace Nidavellir\Trading\Exchanges\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\Exchanges\Binance\REST\Futures;

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
