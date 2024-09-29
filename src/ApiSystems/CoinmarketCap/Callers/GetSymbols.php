<?php

namespace Nidavellir\Trading\ApiSystems\CoinmarketCap\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\ApiSystems\CoinmarketCap\REST\API;

class GetSymbols extends AbstractCaller
{
    protected string $callerName = 'Get Symbols';

    public function call()
    {
        $api = new API($this->mapper->connectionDetails());
        $this->result = $api->getSymbols($this->mapper->properties);
    }
}
