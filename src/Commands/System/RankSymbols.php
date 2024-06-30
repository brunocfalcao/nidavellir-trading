<?php

namespace Nidavellir\Trading\Commands\System;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Nidavellir\Trading\Models\Symbol;

class RankSymbols extends Command
{
    protected $signature = 'trading:rank-symbols';

    protected $description = 'Update the rank of all symbols from Coinmarketcap';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $apiKey = env('COINMARKETCAP_API_KEY');
        if (empty($apiKey)) {
            $this->error('Coinmarketcap API key is not set in the environment variables.');

            return;
        }

        $response = Http::withHeaders([
            'X-CMC_PRO_API_KEY' => $apiKey,
        ])->get('https://pro-api.coinmarketcap.com/v1/cryptocurrency/map');

        if ($response->failed()) {
            $this->error('Failed to fetch data from Coinmarketcap.');

            return;
        }

        $data = $response->json('data');
        if (empty($data)) {
            $this->info('No data found from Coinmarketcap.');

            return;
        }

        $symbols = Symbol::all()->keyBy('coinmarketcap_id');

        foreach ($data as $item) {
            if (isset($symbols[$item['id']])) {
                $symbol = $symbols[$item['id']];
                $symbol->rank = $item['rank'];
                $symbol->save();
                $this->info('Updated: '.$symbol->name.' (Rank: '.$symbol->rank.')');
            }
        }

        $this->info('Symbol ranks update completed.');
    }
}
