<?php

namespace Nidavellir\Trading\ApiSystems\Taapi;

use Nidavellir\Trading\Abstracts\AbstractMapper;
use Nidavellir\Trading\ApiSystems\Taapi\Callers\GetExchangeSymbols;
use Nidavellir\Trading\ApiSystems\Taapi\Callers\GetMa;
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
            'url' => $this->apiSystem()->other_url_prefix,
            'api_key' => $this->credentials['api_key'],
        ];
    }

    public function getExchangeSymbols()
    {
        return (new GetExchangeSymbols($this))->result;
    }

    public function getMa()
    {
        return (new GetMa($this))->result;
    }
}
