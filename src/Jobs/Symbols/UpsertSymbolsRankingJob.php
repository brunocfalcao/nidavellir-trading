<?php

namespace Nidavellir\Trading\Jobs\Symbols;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Exceptions\NidavellirException;
use Nidavellir\Trading\Exchanges\CoinmarketCap\CoinmarketCapRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;
use Throwable;

/**
 * Class: UpsertSymbolsRankingJob
 *
 * This class handles fetching the latest symbol rankings from
 * the CoinMarketCap API and updating the ranks of the existing
 * symbols in the database.
 *
 * Important points:
 * - Uses CoinMarketCap API to fetch the latest rankings.
 * - Updates the ranks only if the new rank is different or
 *   if the current rank is null.
 * - Handles exceptions and throws a custom exception if
 *   any issue occurs during the update process.
 */
class UpsertSymbolsRankingJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Constructor for the job. Currently, it doesn't
     * require any specific initialization.
     */
    public function __construct() {}

    /**
     * Handle the job execution to fetch symbol rankings
     * and update them in the database.
     */
    public function handle()
    {
        try {
            /**
             * Initialize the API wrapper to fetch
             * symbol rankings using system credentials.
             */
            $api = new ExchangeRESTWrapper(
                new CoinmarketCapRESTMapper(
                    credentials: Nidavellir::getSystemCredentials('coinmarketcap')
                )
            );

            /**
             * Fetch the latest symbol rankings from
             * the CoinMarketCap API.
             */
            $symbolsRanking = (array) $api->getSymbolsRanking();

            /**
             * Retrieve all symbols from the database,
             * keyed by their CoinMarketCap ID for faster
             * lookup.
             */
            $symbols = Symbol::all()->keyBy('coinmarketcap_id');

            /**
             * Iterate over the fetched ranking data and
             * update the symbols in the database.
             */
            foreach ($symbolsRanking as $item) {
                $coinmarketcapId = $item['id'];
                $newRank = $item['rank'];

                /**
                 * Check if the symbol exists in the database,
                 * and update the rank if it differs or is null.
                 */
                if (isset($symbols[$coinmarketcapId])) {
                    $symbol = $symbols[$coinmarketcapId];

                    /**
                     * Update the rank only if it's different
                     * or if the current rank is null.
                     */
                    if ($symbol->rank != $newRank || is_null($symbol->rank)) {
                        $symbol->rank = $newRank;
                        $symbol->save();
                    }
                }
            }
        } catch (Throwable $e) {
            /**
             * If an error occurs, throw a custom exception
             * to indicate that symbol ranks were not updated.
             */
            throw new NidavellirException(
                originalException: $e,
                title: 'Error occurred while updating symbol ranks',
                additionalData: [
                    'source' => 'CoinmarketCap API',
                ]
            );
        }
    }
}
