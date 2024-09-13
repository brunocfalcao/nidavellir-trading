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

/**
 * Class: UpsertSymbolsJob
 *
 * This class handles fetching symbols from CoinMarketCap API
 * and upserts them into the database. It allows limiting the
 * number of symbols fetched and performs bulk insert/update
 * for symbols to optimize database operations.
 *
 * Important points:
 * - Uses CoinMarketCap API for symbol data.
 * - Supports limiting the number of symbols fetched.
 * - Performs bulk upsert to avoid unnecessary writes.
 * - Handles exceptions and throws a custom exception
 *   if the operation fails.
 */
class UpsertSymbolsJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int|null Defines the limit for fetching
     *               symbols from the API.
     */
    private ?int $limit;

    /**
     * Constructor to initialize the job with an optional
     * limit for the number of symbols to fetch.
     *
     * @param  int|null  $limit  The limit of symbols to fetch
     */
    public function __construct(?int $limit = null)
    {
        $this->limit = $limit;
    }

    /**
     * Handle the job execution, fetching symbols and
     * performing the upsert operation.
     */
    public function handle()
    {
        try {
            /**
             * Initialize the CoinMarketCap API wrapper
             * using system credentials.
             */
            $api = new ExchangeRESTWrapper(
                new CoinmarketCapRESTMapper(
                    credentials: Nidavellir::getSystemCredentials('coinmarketcap')
                )
            );

            /**
             * Set API options including the limit
             * for the number of symbols to fetch.
             */
            $api->withOptions(['limit' => $this->limit]);

            /**
             * Fetch the symbol data from the CoinMarketCap API.
             */
            $data = $api->getSymbols();

            /**
             * If no data is returned, throw an exception.
             */
            if (! $data) {
                throw new SymbolsNotUpdatedException(message: 'No symbols fetched from CoinMarketCap API.');
            }

            /**
             * Prepare an array for bulk symbol updates
             * to minimize database operations.
             */
            $symbolUpdates = [];

            /**
             * Iterate through the API data and collect symbol
             * information for upserting.
             */
            foreach ($data as $item) {
                /**
                 * Only create/update records if there is
                 * a difference, to avoid unnecessary writes.
                 */
                $symbolUpdates[] = [
                    'coinmarketcap_id' => $item['id'],
                    'name' => $item['name'],
                    'token' => $item['symbol'],
                    'updated_at' => now(),
                ];
            }

            /**
             * Perform bulk upsert using Laravel's upsert
             * method, ensuring uniqueness by coinmarketcap_id.
             */
            Symbol::upsert(
                $symbolUpdates,
                ['coinmarketcap_id'],  // Unique key for upsert
                ['name', 'token', 'updated_at']  // Columns to update if they exist
            );
        } catch (Throwable $e) {
            /**
             * If an error occurs, throw a custom exception
             * with the relevant error message.
             */
            throw new SymbolsNotUpdatedException(
                $e
            );
        }
    }
}
