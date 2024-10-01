<?php

namespace Nidavellir\Trading\Jobs\ApiSystems\CoinmarketCap;

use Nidavellir\Trading\ApiSystems\ApiSystemRESTWrapper;
use Nidavellir\Trading\ApiSystems\CoinmarketCap\CoinmarketCapRESTMapper;
use Nidavellir\Trading\Jobs\ApiJobFoundations\CoinmarketCapApiJob;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;

/**
 * UpsertSymbolMetadataJob is responsible for updating symbol metadata
 * such as image URL, description, and website from the CoinMarketCap
 * API. This job handles symbols missing this information and processes
 * them in chunks for efficient API calls.
 *
 * Important points:
 * - Retrieves symbols missing metadata from the database.
 * - Fetches metadata from CoinMarketCap in batches.
 * - Updates symbol data with the fetched metadata.
 */
class UpsertSymbolMetadataJob extends CoinmarketCapApiJob
{
    // Implement the generalized compute method.
    protected function compute()
    {
        // Initialize the CoinMarketCap API Wrapper.
        $api = new ApiSystemRESTWrapper(
            new CoinmarketCapRESTMapper(
                credentials: Nidavellir::getSystemCredentials('coinmarketcap')
            )
        );

        // Fetch symbols with missing metadata.
        $symbols = Symbol::whereNull('image_url')
            ->orWhereNull('description')
            ->pluck('coinmarketcap_id')
            ->toArray();

        // Process symbols in chunks of 100.
        foreach (array_chunk($symbols, 100) as $chunk) {
            $symbolList = implode(',', $chunk);

            // Fetch metadata from CoinMarketCap.
            $cryptoDataList = (array) $api->withOptions(['id' => $symbolList])
                ->getSymbolsMetadata()['data'];

            // Check if data was retrieved successfully.
            if (! empty($cryptoDataList)) {
                foreach ($cryptoDataList as $cryptoId => $cryptoData) {
                    $imageUrl = $cryptoData['logo'] ?? null;
                    $name = $cryptoData['name'] ?? null;
                    $website = $cryptoData['urls']['website'][0] ?? null;
                    $description = $cryptoData['description'] ?? null;

                    // Update the symbol record if missing metadata.
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
}
