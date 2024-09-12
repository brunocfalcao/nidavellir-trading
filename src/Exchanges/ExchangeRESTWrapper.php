<?php

namespace Nidavellir\Trading\Exchanges;

use Nidavellir\Trading\Abstracts\AbstractRESTWrapper;

class ExchangeRESTWrapper extends AbstractRESTWrapper
{
    public function getAccountInformation()
    {
        return $this->mapper->getAccountInformation($this);
    }

    public function getOpenOrders()
    {
        return $this->mapper->getOpenOrders($this);
    }

    public function getAllOrders()
    {
        return $this->mapper->getAllOrders($this);
    }

    public function placeSingleOrder()
    {
        return $this->mapper->placeOrder($this);
    }

    public function setDefaultLeverage()
    {
        return $this->mapper->setDefaultLeverage($this);
    }

    public function getLeverageBrackets()
    {
        return $this->mapper->getLeverageBrackets($this);
    }

    // CoinmarketCap getSymbols(), populates Symbols.
    public function getSymbols()
    {
        return $this->mapper->getSymbols($this);
    }

    public function getExchangeInformation()
    {
        return $this->mapper->getExchangeInformation($this);
    }

    public function getAccountBalance()
    {
        return $this->mapper->getAccountBalance($this);
    }

    public function getMarkPrice()
    {
        return $this->mapper->getMarkPrice($this);
    }
}
