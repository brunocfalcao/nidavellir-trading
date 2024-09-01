<?php

namespace Nidavellir\Trading\Exchanges\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\Exchanges\Binance\REST\Futures;

class GetMarkPrice extends AbstractCaller
{
    protected string $callerName = 'Get Mark Price';

    public function call()
    {
        $futures = new Futures($this->mapper->connectionDetails());
        $this->result = $futures->markPrice($this->mapper->properties['symbol']);
    }

    public function parseResult()
    {
        $this->result = $this->result['markPrice'];
    }
}
