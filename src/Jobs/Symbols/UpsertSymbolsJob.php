<?php

namespace Nidavellir\Trading\Jobs\Symbols;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\SymbolNotSyncedException;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Exchanges\CoinmarketCap\CoinmarketCapRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;

/**
 * UpsertSymbolsJob handles fetching symbols from CoinMarketCap
 * API and upserts them into the database. It allows limiting
 * the number of symbols fetched and performs bulk insert/update
 * for symbols to optimize database operations.
 */
class UpsertSymbolsJob extends AbstractJob
{
    private ?int $limit;

    /**
     * Constructor to initialize the job with an optional
     * limit for the number of symbols to fetch.
     */
    public function __construct(?int $limit = null)
    {
        // Set the limit for fetching symbols.
        $this->limit = $limit;
    }

    /**
     * Handle the job execution, fetching symbols and
     * performing the upsert operation.
     */
    public function handle()
    {
        try {
            // Initialize the CoinMarketCap API wrapper using system credentials.
            $api = new ExchangeRESTWrapper(
                new CoinmarketCapRESTMapper(
                    credentials: Nidavellir::getSystemCredentials('coinmarketcap')
                )
            );

            // Set API options including the limit for the number of symbols to fetch.
            if ($this->limit) {
                $api->withOptions(['limit' => $this->limit]);
            }

            // Fetch the symbol data from the CoinMarketCap API.
            $data = $api->getSymbols();

            // If no data is returned, throw an exception.
            if (! $data) {
                throw new SymbolNotSyncedException(
                    title: 'No symbols fetched from CoinMarketCap API'
                );
            }

            // Prepare an array for bulk symbol updates.
            $symbolUpdates = [];

            // Iterate through the API data and collect symbol information for upserting.
            foreach ($data as $item) {
                $symbolUpdates[] = [
                    'coinmarketcap_id' => $item['id'],
                    'name' => $item['name'],
                    'token' => $item['symbol'],
                    'updated_at' => now(),
                ];
            }

            // Perform bulk upsert using Laravel's upsert method.
            Symbol::upsert(
                $symbolUpdates,
                ['coinmarketcap_id'],
                ['name', 'token', 'updated_at']
            );
        } catch (\Throwable $e) {
            throw new TryCatchException(
                throwable: $e,
            );
        }
    }
}
