<?php

namespace Nidavellir\Trading\Exchanges\CoinmarketCap\REST;

class API
{
    public string $url;

    public string $key;

    public function __construct(array $args = [])
    {
        $this->url = $args['url'];
        $this->key = $args['key'];
    }
}
