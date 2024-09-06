<?php

namespace Nidavellir\Trading\Exchanges;

use Nidavellir\Trading\Abstracts\AbstractRESTWrapper;

class ExchangeRESTWrapper extends AbstractRESTWrapper
{
    public function placeSingleOrder()
    {
        return $this->mapper->placeOrder($this);
    }

    public function setDefaultLeverage()
    {
        return $this->mapper->setDefaultLeverage($this);
    }

    public function getLeverageBracket()
    {
        return $this->mapper->getLeverageBracket($this);
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

    // TODO / Testing.
    public function setOrder()
    {
        return $this->mapper->setOrder($this);
    }
}
