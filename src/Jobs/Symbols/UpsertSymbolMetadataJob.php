<?php

namespace Nidavellir\Trading\Jobs\Symbols;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Exchanges\CoinmarketCap\CoinmarketCapRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;

class UpsertSymbolMetadataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $api = new ExchangeRESTWrapper(
            new CoinmarketCapRESTMapper(
                credentials: Nidavellir::getSystemCredentials('coinmarketcap')
            )
        );

        $symbols = Symbol::whereNull('image_url')
            ->orWhereNull('description')
            ->pluck('coinmarketcap_id')
            ->toArray();

        foreach (array_chunk($symbols, 100) as $chunk) {
            $symbolList = implode(',', $chunk);

            $cryptoDataList = (array) $api->withOptions(['ids' => $symbolList])
                ->getSymbolsMetadata();

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
