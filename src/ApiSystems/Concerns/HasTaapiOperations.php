<?php

namespace Nidavellir\Trading\ApiSystems\Concerns;

trait HasTaapiOperations
{
    public function getTaapiExchangeSymbols()
    {
        return $this->mapper->getExchangeSymbols($this);
    }

    public function getMa()
    {
        return $this->mapper->getMa($this);
    }
}
