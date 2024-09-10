<?php

namespace Nidavellir\Trading\Exchanges\CoinmarketCap\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\Exchanges\CoinmarketCap\REST\API;

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
