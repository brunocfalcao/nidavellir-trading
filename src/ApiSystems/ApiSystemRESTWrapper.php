<?php

namespace Nidavellir\Trading\ApiSystems;

use Nidavellir\Trading\Abstracts\AbstractRESTWrapper;
use Nidavellir\Trading\ApiSystems\Concerns\HasCoinmarketCapOperations;
use Nidavellir\Trading\ApiSystems\Concerns\HasTaapiOperations;

class ApiSystemRESTWrapper extends AbstractRESTWrapper
{
    use HasCoinmarketCapOperations, HasTaapiOperations;

    public function cancelOrder()
    {
        return $this->mapper->cancelOrder($this);
    }

    public function getAccountInformation()
    {
        return $this->mapper->getAccountInformation($this);
    }

    public function updateMarginType()
    {
        return $this->mapper->updateMarginType($this);
    }

    public function getPositions()
    {
        return $this->mapper->getPositions($this);
    }

    public function getOrder()
    {
        return $this->mapper->getOrder($this);
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
