<?php

namespace Nidavellir\Trading\ApiSystems\Binance;

use Nidavellir\Trading\Abstracts\AbstractMapper;
use Nidavellir\Trading\ApiSystems\Binance\Websockets\Futures;
use Nidavellir\Trading\Models\ApiSystem;

class BinanceWebsocketMapper extends AbstractMapper
{
    public function apiSystem()
    {
        return ApiSystem::firstWhere('canonical', 'binance');
    }

    public function credentials()
    {
        return [
            'url' => $this->apiSystem()->futures_url_websockets_prefix,
            'secret' => $this->credentials['secret_key'],
            'key' => $this->credentials['api_key'],
        ];
    }

    public function markPrices($callback, $eachSecond = true)
    {
        $futures = new Futures($this->credentials());
        return $futures->markPrices($callback, $eachSecond);
    }

    public function markPrice(string $symbol, $callback)
    {
        $futures = new Futures($this->credentials());
        return $futures->markPrice($symbol, $callback);
    }
}
