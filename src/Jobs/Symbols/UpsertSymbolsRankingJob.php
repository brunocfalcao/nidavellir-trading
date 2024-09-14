<?php

namespace Nidavellir\Trading\Jobs\Symbols;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Exchanges\CoinmarketCap\CoinmarketCapRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;
use Nidavellir\Trading\NidavellirException;
use Throwable;

/**
 * UpsertSymbolsRankingJob handles fetching the latest symbol
 * rankings from the CoinMarketCap API and updating the ranks
 * of the existing symbols in the database.
 */
class UpsertSymbolsRankingJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Constructor for the job. Currently, it doesn't require
     * any specific initialization.
     */
    public function __construct()
    {
        // No specific initialization needed for now.
    }

    /**
     * Handles the job execution to fetch symbol rankings
     * and update them in the database.
     */
    public function handle()
    {
        try {
            // Initialize the API wrapper using system credentials.
            $api = new ExchangeRESTWrapper(
                new CoinmarketCapRESTMapper(
                    credentials: Nidavellir::getSystemCredentials('coinmarketcap')
                )
            );

            // Fetch the latest symbol rankings from CoinMarketCap API.
            $symbolsRanking = (array) $api->getSymbolsRanking();

            // Retrieve all symbols from the database, keyed by their CoinMarketCap ID.
            $symbols = Symbol::all()->keyBy('coinmarketcap_id');

            // Iterate over the fetched ranking data and update the symbols.
            foreach ($symbolsRanking as $item) {
                $coinmarketcapId = $item['id'];
                $newRank = $item['rank'];

                // Check if the symbol exists in the database and update the rank if needed.
                if (isset($symbols[$coinmarketcapId])) {
                    $symbol = $symbols[$coinmarketcapId];

                    // Update the rank only if it differs from the current rank or is null.
                    if ($symbol->rank != $newRank || is_null($symbol->rank)) {
                        $symbol->rank = $newRank;
                        $symbol->save();
                    }
                }
            }
        } catch (Throwable $e) {
            // If an error occurs, throw a custom exception.
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
