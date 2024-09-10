<?php

namespace Nidavellir\Trading\Exchanges\CoinmarketCap\REST;

use Illuminate\Support\Facades\Http;

class API
{
    public string $url;

    public string $api_key;

    public function __construct(array $args)
    {
        $this->url = $args['url'];
        $this->api_key = $args['api_key'];
    }

    public function getSymbols(array $properties)
    {
        $limit = data_get($properties['options'], 'limit');

        $url = $this->url.
               '/map?sort=cmc_rank'.
               ($limit ? '&limit='.$limit : null);

        return Http::withHeaders([
            'X-CMC_PRO_API_KEY' => $this->api_key,
        ])->get($url)->json('data');
    }

    public function getSymbolsMetadata(array $properties)
    {
        return Http::withHeaders([
            'X-CMC_PRO_API_KEY' => $this->api_key,
        ])->get('https://pro-api.coinmarketcap.com/v1/cryptocurrency/info', [
            'id' => $properties['options']['ids'],
        ])->json('data');
    }

    public function getSymbolsRanking(array $properties)
    {
        return Http::withHeaders([
            'X-CMC_PRO_API_KEY' => $this->api_key,
        ])->get('https://pro-api.coinmarketcap.com/v1/cryptocurrency/map?sort=cmc_rank')
            ->json('data');
    }
}
