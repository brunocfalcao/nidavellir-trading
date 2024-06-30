<?php

namespace Nidavellir\Trading\Commands\System;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Nidavellir\Trading\Models\Symbol;

class ImportSymbols extends Command
{
    protected $signature = 'trading:import-symbols {maxSymbols?}';

    protected $description = 'Import all symbols from Coinmarketcap into the symbols table';

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

        $maxSymbols = $this->argument('maxSymbols');
        if ($maxSymbols && (! is_numeric($maxSymbols) || $maxSymbols <= 0)) {
            $this->error('The maxSymbols argument must be a positive integer.');

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

        $counter = 0;
        foreach ($data as $item) {
            if ($maxSymbols && $counter >= $maxSymbols) {
                break;
            }

            $symbol = Symbol::updateOrCreate(
                ['coinmarketcap_id' => $item['id']],
                [
                    'name' => $item['name'],
                    'token' => $item['symbol'],
                ]
            );

            $this->info('Imported: '.$symbol->name.' ('.$symbol->token.')');

            $counter++;
        }

        $this->info('Symbols import completed.');
    }
}
