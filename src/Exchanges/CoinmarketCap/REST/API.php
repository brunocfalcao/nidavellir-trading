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
}
