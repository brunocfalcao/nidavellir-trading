<?php

namespace Nidavellir\Trading\Jobs\Symbols;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Nidavellir\Trading\Models\Symbol;

class UpsertSymbolRankings implements ShouldQueue
{
    use Batchable,
        Dispatchable,
        InteractsWithQueue,
        Queueable,
        SerializesModels;

    public function __construct() {}

    public function handle()
    {
        $apiKey = env('COINMARKETCAP_API_KEY');
        if (empty($apiKey)) {
            throw new \Exception('No CoinmarketCap API key defined for symbols upserting. Aborting...');
        }

        $response = Http::withHeaders([
            'X-CMC_PRO_API_KEY' => $apiKey,
        ])->get('https://pro-api.coinmarketcap.com/v1/cryptocurrency/map?sort=cmc_rank');

        if ($response->failed()) {
            throw new \Exception('Failed to fetch data from Coinmarketcap.');
        }

        $data = $response->json('data');
        if (empty($data)) {
            throw new \Exception('No data found from Coinmarketcap.');
        }

        // Fetch all symbols from the database indexed by coinmarketcap_id for quick lookup
        $symbols = Symbol::all()->keyBy('coinmarketcap_id');

        // Iterate over fetched data and update symbols if necessary
        foreach ($data as $item) {
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
