<?php

namespace Nidavellir\Trading\ApiSystems\Taapi\REST;

use Nidavellir\Trading\ApiSystems\Taapi\TaapiAPIClient;

class API extends TaapiAPIClient
{
    public function getExchangeSymbols(array $properties)
    {
        return $this->publicRequest('GET', '/exchange-symbols', $properties);
    }

    public function getMa(array $properties)
    {
        return $this->publicRequest('GET', '/ma', $properties);
    }
}
