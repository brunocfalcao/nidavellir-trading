<?php

namespace Nidavellir\Trading\Jobs\System\Taapi;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\ApiSystems\ApiSystemRESTWrapper;
use Nidavellir\Trading\ApiSystems\Taapi\TaapiRESTMapper;

class UpsertTaapiAvailableSymbols extends AbstractJob
{
    public function handle()
    {
        // Initialize API wrapper for CoinMarketCap using system credentials.
        $api = new ApiSystemRESTWrapper(
            new TaapiRESTMapper(
                credentials: Nidavellir::getSystemCredentials('taapi')
            )
        );
    }
}
