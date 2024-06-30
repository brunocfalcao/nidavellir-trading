<?php

namespace Nidavellir\Trading\Jobs\Symbols;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Nidavellir\Trading\Models\Symbol;

class UpsertSymbolMetadata implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $apiKey = env('COINMARKETCAP_API_KEY');
        $symbols = Symbol::whereNull('image_url')
            ->orWhereNull('description')
            ->pluck('coinmarketcap_id')
            ->toArray();

        foreach (array_chunk($symbols, 100) as $chunk) {
            $symbolList = implode(',', $chunk);

            $response = Http::withHeaders([
                'X-CMC_PRO_API_KEY' => $apiKey,
            ])->get('https://pro-api.coinmarketcap.com/v1/cryptocurrency/info', [
                'id' => $symbolList,
            ]);

            if ($response->failed()) {
                throw new \Exception('Failed to fetch the crypto images and descriptions.');
            }

            $cryptoDataList = $response->json('data');

            foreach ($cryptoDataList as $cryptoId => $cryptoData) {
                $imageUrl = $cryptoData['logo'] ?? null;
                $name = $cryptoData['name'] ?? null;
                $website = $cryptoData['urls']['website'][0] ?? null;
                $description = $cryptoData['description'] ?? null;

                Symbol::where('coinmarketcap_id', $cryptoId)
                    ->where(function ($query) {
                        $query->whereNull('image_url')
                            ->orWhereNull('description')
                            ->orWhereNull('website');
                    })
                    ->update([
                        'name' => $name,
                        'website' => $website,
                        'image_url' => $imageUrl,
                        'description' => $description,
                    ]);
            }
        }
    }
}
