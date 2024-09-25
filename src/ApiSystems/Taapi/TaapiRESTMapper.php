<?php

namespace Nidavellir\Trading\ApiSystems\Taapi;

use Nidavellir\Trading\Abstracts\AbstractMapper;
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
            'url' => $this->apiSystem()->taapi_url_rest_prefix,
            'api_key' => $this->credentials['api_key'],
        ];
    }
}
