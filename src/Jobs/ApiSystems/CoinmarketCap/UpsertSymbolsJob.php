<?php

namespace Nidavellir\Trading\Jobs\ApiSystems\CoinmarketCap;

use Nidavellir\Trading\ApiSystems\ApiSystemRESTWrapper;
use Nidavellir\Trading\ApiSystems\CoinmarketCap\CoinmarketCapRESTMapper;
use Nidavellir\Trading\Exceptions\SymbolNotSyncedException;
use Nidavellir\Trading\Jobs\ApiJobFoundations\CoinmarketCapApiJob;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;

class UpsertSymbolsJob extends CoinmarketCapApiJob
{
    protected ?int $limit;

    public function __construct(?int $limit = null)
    {
        $this->limit = $limit;
    }

    // Implement the generalized executeApiLogic method
    protected function executeApiLogic()
    {
        // Initialize the CoinMarketCap API Wrapper
        $api = new ApiSystemRESTWrapper(
            new CoinmarketCapRESTMapper(
                credentials: Nidavellir::getSystemCredentials('coinmarketcap')
            )
        );

        // Set limit if provided
        if ($this->limit) {
            $api->withOptions(['limit' => $this->limit]);
        }

        // Fetch symbols data from CoinMarketCap
        $data = $api->getSymbols()['data'];

        // Check if data was retrieved
        if (! $data) {
            throw new SymbolNotSyncedException(
                message: 'No symbols fetched from CoinMarketCap API'
            );
        }

        // Prepare data for upsert
        $symbolUpdates = array_map(function ($item) {
            return [
                'coinmarketcap_id' => $item['id'],
                'name' => $item['name'],
                'rank' => $item['rank'],
                'token' => $item['symbol'],
                'updated_at' => now(),
            ];
        }, $data);

        // Upsert symbols into the database
        Symbol::upsert(
            $symbolUpdates,
            ['coinmarketcap_id'], // Unique key for upserting
            ['name', 'token', 'updated_at'] // Fields to update if a match is found
        );
    }
}
