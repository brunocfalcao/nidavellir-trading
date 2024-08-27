<?php

namespace Nidavellir\Trading\Exchanges\Binance\Websockets;

use Binance\Websocket;

class Futures extends Websocket
{
    public function __construct(array $args = [])
    {
        $args['baseURL'] = $args['url'];
        parent::__construct($args);
    }

    //https://developers.binance.com/docs/derivatives/usds-margined-futures/websocket-market-streams/Mark-Price-Stream-for-All-market
    public function markPrices($callback, $oneSecond = true)
    {
        if ($oneSecond) {
            $url = "{$this->baseURL}/ws/!markPrice@arr@1s";
        } else {
            $url = "{$this->baseURL}/ws/!markPrice@arr";
        }
        $this->handleCallBack($url, $callback);
    }

    //https://developers.binance.com/docs/derivatives/usds-margined-futures/websocket-market-streams/Mark-Price-Stream
    public function markPrice(string $symbol, $callback)
    {
        $url = "{$this->baseURL}/ws/".strtolower($symbol).'@markPrice@1s';
        $this->handleCallBack($url, $callback);
    }
}
