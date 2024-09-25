<?php

namespace Nidavellir\Trading\ApiSystems\Taapi\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\ApiSystems\Taapi\REST\API;

class GetExchangeSymbols extends AbstractCaller
{
    protected string $callerName = 'Get Taapi Exchange Symbols';

    public function call()
    {
        $api = new API($this->mapper->connectionDetails());

        $this->result = $api->getExchangeSymbols(
            $this->mapper->properties
        );
    }
}
