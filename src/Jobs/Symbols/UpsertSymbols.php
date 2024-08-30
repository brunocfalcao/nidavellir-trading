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

class UpsertSymbols implements ShouldQueue
{
    use Batchable,
        Dispatchable,
        InteractsWithQueue,
        Queueable,
        SerializesModels;

    private int $limit;

    public function __construct(?int $limit = null)
    {
        $this->limit = $limit;
    }

    public function handle()
    {
        $apiKey = env('COINMARKETCAP_API_KEY');
        if (empty($apiKey)) {
            throw new \Exception('No CoinmarketCap API key defined for symbols upserting. Aborting...');

            return;
        }

        $url = 'https://pro-api.coinmarketcap.com/v1/cryptocurrency/map?sort=cmc_rank'.($this->limit ? '&limit='.$this->limit : null);

        $response = Http::withHeaders([
            'X-CMC_PRO_API_KEY' => $apiKey,
        ])->get($url);

        if ($response->failed()) {
            throw new \Exception('Failed to fetch data from Coinmarketcap.');

            return;
        }

        $data = $response->json('data');
        if (empty($data)) {
            throw new \Exception('No data found from Coinmarketcap.');

            return;
        }

        foreach ($data as $item) {
            $symbol = Symbol::updateOrCreate(
                ['coinmarketcap_id' => $item['id']],
                [
                    'name' => $item['name'],
                    'token' => $item['symbol']
                ]
            );
        }
    }
}
