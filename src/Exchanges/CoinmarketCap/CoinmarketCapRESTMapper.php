<?php

namespace Nidavellir\Trading\Exchanges\CoinmarketCap;

use Nidavellir\Trading\Abstracts\AbstractMapper;
use Nidavellir\Trading\Exchanges\CoinmarketCap\Callers\GetSymbols;
use Nidavellir\Trading\Exchanges\CoinmarketCap\Callers\GetSymbolsMetadata;
use Nidavellir\Trading\Exchanges\CoinmarketCap\Callers\GetSymbolsRanking;
use Nidavellir\Trading\Models\Exchange;

class CoinmarketCapRESTMapper extends AbstractMapper
{
    public function exchange()
    {
        return Exchange::firstWhere('canonical', 'coinmarketcap');
    }

    public function connectionDetails()
    {
        return [
            'url' => $this->exchange()->generic_url_prefix,
            'api_key' => $this->credentials['api_key'],
        ];
    }

    public function getSymbols()
    {
        return (new GetSymbols($this))->result;
    }

    public function getSymbolsMetadata()
    {
        return (new GetSymbolsMetadata($this))->result;
    }

    public function getSymbolsRanking()
    {
        return (new GetSymbolsRanking($this))->result;
    }
}
