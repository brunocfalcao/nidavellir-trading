<?php

namespace Nidavellir\Trading\Jobs\Symbols;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Exceptions\SymbolsNotUpdatedException;
use Nidavellir\Trading\Exchanges\CoinmarketCap\CoinmarketCapRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;
use Throwable;

class UpsertSymbolsJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private ?int $limit;

    public function __construct(?int $limit = null)
    {
        $this->limit = $limit;
    }

    public function handle()
    {
        try {
            // Initialize the API wrapper to fetch symbols
            $api = new ExchangeRESTWrapper(
                new CoinmarketCapRESTMapper(
                    credentials: Nidavellir::getSystemCredentials('coinmarketcap')
                )
            );

            // Set API options like limit
            $api->withOptions(['limit' => $this->limit]);

            // Fetch symbols from the API
            $data = $api->getSymbols();

            if (! $data) {
                throw new SymbolsNotUpdatedException('No symbols fetched from CoinMarketCap API.');
            }

            // Prepare for bulk update/create in the database
            $symbolUpdates = [];

            foreach ($data as $item) {
                // Only update/create when the data is actually different to avoid unnecessary writes
                $symbolUpdates[] = [
                    'coinmarketcap_id' => $item['id'],
                    'name' => $item['name'],
                    'token' => $item['symbol'],
                    'updated_at' => now(),
                ];
            }

            // Bulk upsert symbols (Laravel's `upsert` method)
            Symbol::upsert(
                $symbolUpdates,
                ['coinmarketcap_id'],  // Unique key
                ['name', 'token', 'updated_at']  // Columns to update if they already exist
            );
        } catch (Throwable $e) {
            // Throw custom exception in case of an error
            throw new SymbolsNotUpdatedException(
                $e
            );
        }
    }
}
