<?php

namespace Nidavellir\Trading\ApiSystems\Taapi\REST;

use Nidavellir\Trading\ApiSystems\Taapi\TaapiAPIClient;

class API extends TaapiAPIClient
{
    public function getExchangeSymbols(array $properties)
    {
        // Use the publicRequest method from TaapiAPIClient to fetch the symbols
        return $this->publicRequest('GET', '/exchange-symbols', [
            'exchange' => $properties['options']['exchange'],
        ]);
    }
}
