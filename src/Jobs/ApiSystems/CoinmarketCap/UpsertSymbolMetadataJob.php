<?php

namespace Nidavellir\Trading\Jobs\ApiSystems\CoinmarketCap;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\ApiSystems\ApiSystemRESTWrapper;
use Nidavellir\Trading\ApiSystems\CoinmarketCap\CoinmarketCapRESTMapper;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Exceptions\UpsertSymbolMetadataException;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;

/**
 * UpsertSymbolMetadataJob fetches and updates metadata for
 * cryptocurrency symbols that have missing information such
 * as image URLs, descriptions, or websites.
 *
 * - It only processes symbols with null values for image,
 * description, or website.
 * - Uses custom exception handling to manage errors.
 * - Updates metadata efficiently using array chunks.
 */
class UpsertSymbolMetadataJob extends AbstractJob
{
    /**
     * Handle the job to upsert symbol metadata.
     *
     * Fetches and updates metadata for symbols missing metadata
     * (e.g., image or description).
     */
    public function handle()
    {
        try {
            // Initialize API wrapper for CoinMarketCap using system credentials.
            $api = new ApiSystemRESTWrapper(
                new CoinmarketCapRESTMapper(
                    credentials: Nidavellir::getSystemCredentials('coinmarketcap')
                )
            );

            // Get all symbols that are missing metadata (image_url, description).
            $symbols = Symbol::whereNull('image_url')
                ->orWhereNull('description')
                ->pluck('coinmarketcap_id')
                ->toArray();

            // Process symbols in chunks to avoid large requests (up to 100 symbols per chunk).
            foreach (array_chunk($symbols, 100) as $chunk) {
                $symbolList = implode(',', $chunk);

                // Fetch metadata for the current chunk of symbols.
                $cryptoDataList = (array) $api->withOptions(['id' => $symbolList])
                    ->getSymbolsMetadata()['data'];

                // Throw an exception if no metadata is returned.
                if (! empty($cryptoDataList)) {
                    // Update symbols with the fetched metadata.
                    foreach ($cryptoDataList as $cryptoId => $cryptoData) {
                        $imageUrl = $cryptoData['logo'] ?? null;
                        $name = $cryptoData['name'] ?? null;
                        $website = $cryptoData['urls']['website'][0] ?? null;
                        $description = $cryptoData['description'] ?? null;

                        // Only update the symbol if its metadata fields are null.
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
            throw new UpsertSymbolMetadataException(
                message: 'test error'
            );

            $this->jobPollerInstance->markAsComplete();
        } catch (\Throwable $e) {
            $this->jobPollerInstance->markAsError($e);
            // Catch any exceptions and rethrow a custom exception.
            throw new TryCatchException(
                throwable: $e,
            );
        }
    }
}
