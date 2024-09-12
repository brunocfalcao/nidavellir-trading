<?php

namespace Nidavellir\Trading\Exchanges\Binance;

use Nidavellir\Trading\Abstracts\AbstractMapper;
use Nidavellir\Trading\Exchanges\Binance\Callers\GetAccountBalance;
use Nidavellir\Trading\Exchanges\Binance\Callers\GetAccountInformation;
use Nidavellir\Trading\Exchanges\Binance\Callers\GetAllOrders;
use Nidavellir\Trading\Exchanges\Binance\Callers\GetExchangeInformation;
use Nidavellir\Trading\Exchanges\Binance\Callers\GetLeverageBracket;
use Nidavellir\Trading\Exchanges\Binance\Callers\GetMarkPrice;
use Nidavellir\Trading\Exchanges\Binance\Callers\GetOpenOrders;
use Nidavellir\Trading\Exchanges\Binance\Callers\PlaceOrder;
use Nidavellir\Trading\Exchanges\Binance\Callers\SetDefaultLeverage;
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

    public function getAccountBalance()
    {
        return (new getAccountBalance($this))->result;
    }

    public function getMarkPrice()
    {
        return (new GetMarkPrice($this))->result;
    }

    public function getLeverageBrackets()
    {
        return (new GetLeverageBracket($this))->result;
    }

    public function setDefaultLeverage()
    {
        return (new SetDefaultLeverage($this))->result;
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
    public function placeOrder()
    {
        return (new PlaceOrder($this))->result;
    }

    public function getOpenOrders()
    {
        return (new GetOpenOrders($this))->result;
    }

    public function getAllOrders()
    {
        return (new GetAllOrders($this))->result;
    }

    public function getAccountInformation()
    {
        return (new GetAccountInformation($this))->result;
    }
}
