<?php

namespace Nidavellir\Trading\Exchanges\CoinmarketCap\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\Exchanges\CoinmarketCap\REST\API;

class GetSymbols extends AbstractCaller
{
    protected string $callerName = 'Get Symbols';

    public function call()
    {
        dd('inside call');
        $api = new API($this->mapper->connectionDetails());
        $this->result = $api->getSymbols();
    }
}
