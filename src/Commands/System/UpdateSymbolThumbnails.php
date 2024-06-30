<?php

namespace Nidavellir\Trading\Commands\System;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Nidavellir\Trading\Models\Symbol;

class UpdateSymbolThumbnails extends Command
{
    protected $signature = 'trading:update-symbol-thumbnails {chunkSize=100}';

    protected $description = 'Update token thumbnails from CoinMarketCap API for symbols missing image links';

    public function handle()
    {
        $apiKey = env('COINMARKETCAP_API_KEY');
        $chunkSize = $this->argument('chunkSize');

        $symbols = Symbol::whereNull('image_url')->pluck('token')->toArray();

        foreach (array_chunk($symbols, $chunkSize) as $chunk) {
            $symbolList = implode(',', $chunk);

            $response = Http::withHeaders([
                'X-CMC_PRO_API_KEY' => $apiKey,
            ])->get('https://pro-api.coinmarketcap.com/v1/cryptocurrency/info', [
                'symbol' => $symbolList,
            ]);

            if ($response->failed()) {
                $this->error('Failed to fetch the crypto images.');

                continue;
            }

            $cryptoDataList = $response->json('data');

            foreach ($cryptoDataList as $cryptoData) {
                $cryptoSymbol = $cryptoData['symbol'];
                $imageUrl = $cryptoData['logo'];
                $name = $cryptoData['name'];
                $website = $cryptoData['urls']['website'][0];
                $description = $cryptoData['description'];

                Symbol::where('token', $cryptoSymbol)->update([
                    'name' => $name,
                    'image_url' => $imageUrl,
                    'website' => $website,
                    'description' => $description,
                ]);

                $this->info('Updated image URL and description for token: '.$cryptoSymbol);
            }
        }

        $this->info('All crypto images and descriptions have been updated successfully.');
    }
}
