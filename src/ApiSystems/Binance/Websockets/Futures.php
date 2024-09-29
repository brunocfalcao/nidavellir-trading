<?php

namespace Nidavellir\Trading\ApiSystems\Binance\Websockets;

use Binance\Websocket;

class Futures extends Websocket
{
    public function __construct(array $args = [])
    {
        $args['baseURL'] = $args['url'];
        parent::__construct($args);
    }

    public function markPrices($callback, $oneSecond = true)
    {
        $url = $oneSecond
            ? "{$this->baseURL}/ws/!markPrice@arr@1s"
            : "{$this->baseURL}/ws/!markPrice@arr";

        $this->handleCallBack($url, $callback);
    }

    public function markPrice(string $symbol, $callback)
    {
        $url = "{$this->baseURL}/ws/".strtolower($symbol).'@markPrice@1s';
        $this->handleCallBack($url, $callback);
    }
}
