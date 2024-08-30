<?php

namespace Nidavellir\Trading\Concerns\Models;

use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;

trait HasTraderFeatures
{
    /**
     * Returns the exchange credentias that this trader is using.
     *
     * The credentials array need to match the <Exchange><Type>Mapper.php
     * credentials() method.
     */
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
        return new ExchangeRESTWrapper($this->getExchangeWrapperInUse());
    }

    // Returns the exchange class REST name to be instanciated.
    protected function getExchangeWrapperInUse()
    {
        $className = $this->exchange->full_qualified_class_name_rest;

        /**
         * Return an exchange mapper, that is being used by
         * this trader, with himself authenticated.
         */
        return new $className($this);
    }

    // Returns the exchange class Websocket name to be instanciated.
    public function getExchangeWebsocketMapper()
    {
        $className = $this->exchange->full_qualified_class_name_websocket;

        return new $className($this);
    }
}
