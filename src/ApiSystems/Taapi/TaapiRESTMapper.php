<?php

namespace Nidavellir\Trading\ApiSystems\Taapi;

use Nidavellir\Trading\Abstracts\AbstractMapper;
use Nidavellir\Trading\ApiSystems\CoinmarketCap\Callers\GetSymbols;
use Nidavellir\Trading\ApiSystems\CoinmarketCap\Callers\GetSymbolsMetadata;
use Nidavellir\Trading\ApiSystems\CoinmarketCap\Callers\GetSymbolsRanking;
use Nidavellir\Trading\Models\ApiSystem;

class TaapiRESTMapper extends AbstractMapper
{
    public function apiSystem()
    {
        return ApiSystem::firstWhere('canonical', 'taapi');
    }

    public function connectionDetails()
    {
        return [
            'url' => $this->apiSystem()->generic_url_prefix,
            'api_key' => $this->credentials['api_key'],
        ];
    }
}
