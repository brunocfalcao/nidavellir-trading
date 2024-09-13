<?php

namespace Nidavellir\Trading\Jobs\Symbols;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Exceptions\SymbolRanksNotUpdatedException;
use Nidavellir\Trading\Exchanges\CoinmarketCap\CoinmarketCapRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;
use Throwable;

class UpsertSymbolsRankingJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct() {}

    public function handle()
    {
        try {
            // Initialize the API wrapper to fetch symbol rankings
            $api = new ExchangeRESTWrapper(
                new CoinmarketCapRESTMapper(
                    credentials: Nidavellir::getSystemCredentials('coinmarketcap')
                )
            );

            // Fetch the latest symbol rankings from the API
            $symbolsRanking = (array) $api->getSymbolsRanking();

            // Fetch all symbols from the database indexed by coinmarketcap_id for quick lookup
            $symbols = Symbol::all()->keyBy('coinmarketcap_id');

            // Iterate over the fetched data and update symbols
            foreach ($symbolsRanking as $item) {
                $coinmarketcapId = $item['id'];
                $newRank = $item['rank'];

                // Check if the symbol exists in the database
                if (isset($symbols[$coinmarketcapId])) {
                    $symbol = $symbols[$coinmarketcapId];

                    // Update the rank if it is different or if the current rank is null
                    if ($symbol->rank != $newRank || is_null($symbol->rank)) {
                        $symbol->rank = $newRank;
                        $symbol->save();
                    }
                }
            }
        } catch (Throwable $e) {
            // Raise a custom exception if the update fails
            throw new SymbolRanksNotUpdatedException(
                $e
            );
        }
    }
}
