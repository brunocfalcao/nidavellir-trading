<?php

namespace Nidavellir\Trading\ApiSystems\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\ApiSystems\Binance\REST\Futures;

class GetLeverageBracket extends AbstractCaller
{
    protected string $callerName = 'Get Leverage Bracket';

    public function call()
    {
        $futures = new Futures($this->mapper->connectionDetails());
        $this->result = $futures->leverageBracket(
            $this->mapper->properties
        );
    }
}
