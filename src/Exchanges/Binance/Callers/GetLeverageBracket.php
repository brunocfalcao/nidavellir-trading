<?php

namespace Nidavellir\Trading\Exchanges\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\Exchanges\Binance\REST\Futures;

class GetLeverageBracket extends AbstractCaller
{
    protected string $callerName = 'Get Leverage Bracket';

    public function call()
    {
        $futures = new Futures($this->mapper->connectionDetails());
        $this->result = $futures->leverageBracket(
            $this->mapper->properties['options']
        );
    }
}
