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

class UpsertSymbolsRankingJob implements ShouldQueue
{
    use Batchable,
        Dispatchable,
        InteractsWithQueue,
        Queueable,
        SerializesModels;

    public function __construct() {}

    public function handle()
    {
        $api = new ExchangeRESTWrapper(
            new CoinmarketCapRESTMapper(
                credentials: Nidavellir::getSystemCredentials('coinmarketcap')
            )
        );

        $symbolsRanking = (array) $api->getSymbolsRanking();

        // Fetch all symbols from the database indexed by coinmarketcap_id for quick lookup
        $symbols = Symbol::all()->keyBy('coinmarketcap_id');

        // Iterate over fetched data and update symbols if necessary
        foreach ($symbolsRanking as $item) {
            if (isset($symbols[$item['id']])) {
                $symbol = $symbols[$item['id']];

                // Update the symbol's rank if it's different
                if ($symbol->rank != $item['rank']) {
                    $symbol->rank = $item['rank'];
                    $symbol->save();
                }
            }
        }
    }
}
