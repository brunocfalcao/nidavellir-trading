<?php

namespace Nidavellir\Trading\ApiSystems\Binance;

use Nidavellir\Trading\Abstracts\AbstractMapper;
use Nidavellir\Trading\ApiSystems\Binance\Websockets\Futures;
use Nidavellir\Trading\Models\Exchange;

/**
 * The Mapper translates actions into methods, in a standard
 * way, due to the fact that the exchange api methods can
 * have different name and parameter signatures.
 */
class BinanceWebsocketMapper extends AbstractMapper
{
    /**
     * Returns the exchange model instance by canonical.
     */
    public function exchange(): Exchange
    {
        return Exchange::firstWhere('canonical', 'binance');
    }

    /**
     * Returns FUTURES credentials.
     */
    public function credentials(): array
    {
        return [
            'url' => $this->exchange()->futures_url_websockets_prefix,
            'secret' => $this->credentials['secret_key'],
            'key' => $this->credentials['api_key'],
        ];
    }

    //https://developers.binance.com/docs/derivatives/usds-margined-futures/websocket-market-streams/Mark-Price-Stream-for-All-market
    public function markPrices($callback, $eachSecond = true)
    {
        $futures = new Futures($this->credentials());

        return $futures->markPrices($callback, $eachSecond);
    }

    //https://developers.binance.com/docs/derivatives/usds-margined-futures/websocket-market-streams/Mark-Price-Stream
    // TODO!
    public function markPrice(string $symbol, $callback)
    {
        $futures = new Futures($this->credentials());

        return $futures->markPrice($symbol, $callback);
    }
}
