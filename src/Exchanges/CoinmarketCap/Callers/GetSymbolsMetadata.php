<?php

namespace Nidavellir\Trading\Exchanges\CoinmarketCap\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\Exchanges\CoinmarketCap\REST\API;

class GetSymbolsMetadata extends AbstractCaller
{
    protected string $callerName = 'Get Symbol(s) metadata';

    public function call()
    {
        $api = new API($this->mapper->connectionDetails());

        $this->result = $api->getSymbolsMetadata(
            $this->mapper->properties
        );
    }
}
