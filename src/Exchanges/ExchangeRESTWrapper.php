<?php

namespace Nidavellir\Trading\Exchanges;

use Nidavellir\Trading\Abstracts\AbstractRESTWrapper;

class ExchangeRESTWrapper extends AbstractRESTWrapper
{
    // Get symbols rankings.
    public function getSymbolsRanking()
    {
        return $this->mapper->getSymbolsRanking($this);
    }

    // Get additional information from a symbol(s).
    public function getSymbolsMetadata()
    {
        return $this->mapper->getSymbolsMetadata($this);
    }

    // CoinmarketCap getSymbols(), populates Symbols.
    public function getSymbols()
    {
        return $this->mapper->getSymbols($this);
    }
}
