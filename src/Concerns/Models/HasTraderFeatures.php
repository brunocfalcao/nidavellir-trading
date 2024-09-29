<?php

namespace Nidavellir\Trading\Concerns\Models;

use Nidavellir\Trading\ApiSystems\ApiSystemRESTWrapper;

trait HasTraderFeatures
{
    public function getExchangeCredentials()
    {
        switch ($this->exchange->canonical) {
            case 'binance':
                return [
                    'secret_key' => $this->binance_secret_key,
                    'api_key' => $this->binance_api_key,
                ];
                break;
        }
    }

    public function withRESTApi()
    {
        return new ApiSystemRESTWrapper($this->getExchangeWrapperInUse());
    }

    protected function getExchangeWrapperInUse()
    {
        $className = $this->exchange->namespace_class_rest;
        return new $className($this);
    }

    public function getExchangeWebsocketMapper()
    {
        $className = $this->exchange->namespace_class_websocket;
        return new $className($this);
    }
}
