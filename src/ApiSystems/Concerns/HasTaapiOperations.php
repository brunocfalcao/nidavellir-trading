<?php

namespace Nidavellir\Trading\ApiSystems\Concerns;

trait HasTaapiOperations
{
    // https://taapi.io/exchanges/binance/#symbols
    public function getTaapiExchangeSymbols()
    {
        return $this->mapper->getExchangeSymbols($this);
    }

    // https://taapi.io/indicators/moving-average/
    public function getMa()
    {
        return $this->mapper->getMa($this);
    }
}
