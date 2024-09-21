<?php

namespace Nidavellir\Trading\ApiSystems\CoinmarketCap\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\ApiSystems\CoinmarketCap\REST\API;

class GetSymbolsRanking extends AbstractCaller
{
    protected string $callerName = 'Get Symbols ranking';

    public function call()
    {
        $api = new API($this->mapper->connectionDetails());

        $this->result = $api->getSymbolsRanking(
            $this->mapper->properties
        );
    }
}
