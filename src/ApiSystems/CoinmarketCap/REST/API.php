<?php

namespace Nidavellir\Trading\ApiSystems\CoinmarketCap\REST;

use Nidavellir\Trading\ApiSystems\CoinmarketCap\CoinmarketCapAPIClient;

class API extends CoinmarketCapAPIClient
{
    public function getSymbols(array $properties)
    {
        $limit = data_get($properties, 'options.limit');

        $properties['options']['sort'] = 'cmc_rank';

        return $this->publicRequest(
            'GET',
            '/v1/cryptocurrency/map?'.
            ($limit ? '&limit='.$limit : null),
            $properties
        );
    }

    public function getSymbolsMetadata(array $properties)
    {
        return $this->publicRequest(
            'GET',
            '/v1/cryptocurrency/info?',
            $properties
        );
    }
}
