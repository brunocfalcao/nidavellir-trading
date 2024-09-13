<?php

namespace Nidavellir\Trading\Jobs\Symbols;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Exceptions\SymbolsMetadataNotUpdatedException;
use Nidavellir\Trading\Exchanges\CoinmarketCap\CoinmarketCapRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;
use Throwable;

class UpsertSymbolMetadataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        try {
            // Initialize the API wrapper
            $api = new ExchangeRESTWrapper(
                new CoinmarketCapRESTMapper(
                    credentials: Nidavellir::getSystemCredentials('coinmarketcap')
                )
            );

            // Get all symbols with missing metadata (image or description)
            $symbols = Symbol::whereNull('image_url')
                ->orWhereNull('description')
                ->pluck('coinmarketcap_id')
                ->toArray();

            if (empty($symbols)) {
                throw new SymbolsMetadataNotUpdatedException(message: 'No symbols with missing metadata found.');
            }

            // Process symbols in chunks of 100 to avoid large requests
            foreach (array_chunk($symbols, 100) as $chunk) {
                $symbolList = implode(',', $chunk);

                // Fetch metadata for the current chunk
                $cryptoDataList = (array) $api->withOptions(['ids' => $symbolList])
                    ->getSymbolsMetadata();

                if (empty($cryptoDataList)) {
                    throw new SymbolsMetadataNotUpdatedException(message: 'No metadata returned from the API.');
                }

                // Update each symbol in the chunk with the fetched metadata
                foreach ($cryptoDataList as $cryptoId => $cryptoData) {
                    $imageUrl = $cryptoData['logo'] ?? null;
                    $name = $cryptoData['name'] ?? null;
                    $website = $cryptoData['urls']['website'][0] ?? null;
                    $description = $cryptoData['description'] ?? null;

                    // Update only if metadata is missing
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
        } catch (Throwable $e) {
            // Throw custom exception if something goes wrong
            throw new SymbolsMetadataNotUpdatedException(
                $e
            );
        }
    }
}
