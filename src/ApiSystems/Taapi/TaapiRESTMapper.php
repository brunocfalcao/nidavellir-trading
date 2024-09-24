<?php

namespace Nidavellir\Trading\ApiSystems\Taapi;

use Nidavellir\Trading\Abstracts\AbstractMapper;
use Nidavellir\Trading\ApiSystems\CoinmarketCap\Callers\GetSymbols;
use Nidavellir\Trading\ApiSystems\CoinmarketCap\Callers\GetSymbolsMetadata;
use Nidavellir\Trading\ApiSystems\CoinmarketCap\Callers\GetSymbolsRanking;
use Nidavellir\Trading\Models\ApiSystem;

class TaapiRESTMapper extends AbstractMapper
{
    public function exchange()
    {
        return ApiSystem::firstWhere('canonical', 'taapi');
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
