<?php

namespace Nidavellir\Trading\ApiSystems\Taapi\REST;

use Illuminate\Support\Facades\Http;

class API
{
    public string $url;

    public string $api_key;

    public string $canonical;

    public function __construct(array $args)
    {
        $this->url = $args['url'];
        $this->api_key = $args['api_key'];
        $this->canonical = $args['canonical'];
    }

    public function getExchangeSymbols(array $properties)
    {
        dd('at the end of the endpoint');
    }
}
