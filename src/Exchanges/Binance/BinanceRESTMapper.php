<?php

namespace Nidavellir\Trading\Exchanges\Binance;

use Nidavellir\Trading\Abstracts\AbstractMapper;
use Nidavellir\Trading\Exchanges\Binance\Callers\GetAccountBalance;
use Nidavellir\Trading\Exchanges\Binance\Callers\GetExchangeInformation;
use Nidavellir\Trading\Exchanges\Binance\Callers\GetLeverageBracket;
use Nidavellir\Trading\Exchanges\Binance\Callers\GetMarkPrice;
use Nidavellir\Trading\Exchanges\Binance\Callers\PlaceOrder;
use Nidavellir\Trading\Exchanges\Binance\REST\Futures;
use Nidavellir\Trading\Models\Exchange;

/**
 * The Mapper translates actions into methods, in a standard
 * way, due to the fact that the exchange api methods can
 * have different name and parameter signatures.
 */
class BinanceRESTMapper extends AbstractMapper
{
    public function exchange()
    {
        return Exchange::firstWhere('canonical', 'binance');
    }

    public function connectionDetails()
    {
        return [
            'url' => $this->exchange()->futures_url_rest_prefix,
            'secret' => $this->credentials['secret_key'],
            'key' => $this->credentials['api_key'],
        ];
    }

    public function getExchangeInformation()
    {
        return (new GetExchangeInformation($this))->result;
    }

    /**
     * Returns the Futures account balance, only for the
     * ones that are not zero.
     *
     * Return:
     * ['ETH' => 6.24,
     *  'USDT' => 330.11]
     */
    public function getAccountBalance()
    {
        return (new getAccountBalance($this))->result;
    }

    /**
     * Returns a mark price for a specific symbol.
     */
    public function getMarkPrice()
    {
        return (new GetMarkPrice($this))->result;
    }

    public function getLeverageBracket()
    {
        return (new GetLeverageBracket($this))->result;
    }

    /**
     * Places an order on the system, via REST api call.
     * string $symbol, string $side, string $type, array $options = []
     * ['symbol-currency'=> '', (SOL-USDT)
     *  'side' => '', BUY/SELL
     *  'type' => '' MARKET/LIMIT,
     *  'quantity' => 500 quantity of token,
     *  'price' => 45.56 if it's limit order
     */
    public function setOrder()
    {
        return (new PlaceOrder($this))->result;
    }
}
