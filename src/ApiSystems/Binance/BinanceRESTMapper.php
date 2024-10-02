<?php

namespace Nidavellir\Trading\ApiSystems\Binance;

use Nidavellir\Trading\Abstracts\AbstractMapper;
use Nidavellir\Trading\ApiSystems\Binance\Callers\CancelOpenOrders;
use Nidavellir\Trading\ApiSystems\Binance\Callers\CancelOrder;
use Nidavellir\Trading\ApiSystems\Binance\Callers\GetAccountBalance;
use Nidavellir\Trading\ApiSystems\Binance\Callers\GetAccountInformation;
use Nidavellir\Trading\ApiSystems\Binance\Callers\GetAllOrders;
use Nidavellir\Trading\ApiSystems\Binance\Callers\GetExchangeInformation;
use Nidavellir\Trading\ApiSystems\Binance\Callers\GetLeverageBracket;
use Nidavellir\Trading\ApiSystems\Binance\Callers\GetMarkPrice;
use Nidavellir\Trading\ApiSystems\Binance\Callers\GetOpenOrders;
use Nidavellir\Trading\ApiSystems\Binance\Callers\GetOrder;
use Nidavellir\Trading\ApiSystems\Binance\Callers\GetPositions;
use Nidavellir\Trading\ApiSystems\Binance\Callers\PlaceOrder;
use Nidavellir\Trading\ApiSystems\Binance\Callers\SetDefaultLeverage;
use Nidavellir\Trading\ApiSystems\Binance\Callers\UpdateMarginType;
use Nidavellir\Trading\Models\ApiSystem;

class BinanceRESTMapper extends AbstractMapper
{
    public function apiSystem()
    {
        return ApiSystem::firstWhere('canonical', 'binance');
    }

    public function connectionDetails()
    {
        return [
            'baseURL' => $this->apiSystem()->futures_url_rest_prefix,
            'secret' => $this->credentials['secret_key'],
            'key' => $this->credentials['api_key'],
            'exchange' => $this->apiSystem(),
        ];
    }

    public function cancelOpenOrders()
    {
        return (new CancelOpenOrders($this))->result;
    }

    public function cancelOrder()
    {
        return (new CancelOrder($this))->result;
    }

    public function getPositions()
    {
        return (new GetPositions($this))->result;
    }

    public function getExchangeInformation()
    {
        return (new GetExchangeInformation($this))->result;
    }

    public function updateMarginType()
    {
        return (new UpdateMarginType($this))->result;
    }

    public function getAccountBalance()
    {
        return (new GetAccountBalance($this))->result;
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

    public function placeOrder()
    {
        return (new PlaceOrder($this))->result;
    }

    public function getOrder()
    {
        return (new GetOrder($this))->result;
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
