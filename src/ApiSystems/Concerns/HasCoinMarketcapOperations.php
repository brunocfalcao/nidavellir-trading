<?php

namespace Nidavellir\Trading\ApiSystems\Concerns;

trait HasCoinmarketCapOperations
{
    public function getSymbols()
    {
        return $this->mapper->getSymbols($this);
    }

    public function getSymbolsRanking()
    {
        return $this->mapper->getSymbolsRanking($this);
    }

    public function getSymbolsMetadata()
    {
        return $this->mapper->getSymbolsMetadata($this);
    }
}
