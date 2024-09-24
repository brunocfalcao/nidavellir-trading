<?php

namespace Nidavellir\Trading\Jobs\Symbols;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\ApiSystems\CoinmarketCap\CoinmarketCapRESTMapper;
use Nidavellir\Trading\ApiSystems\ApiSystemRESTWrapper;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;
use Nidavellir\Trading\NidavellirException;

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
    public function __construct()
    {
        //
    }

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
                $cryptoDataList = (array) $api->withOptions(['ids' => $symbolList])
                    ->getSymbolsMetadata();

                // Throw an exception if no metadata is returned.
                if (empty($cryptoDataList)) {
                    throw new NidavellirException(
                        title: 'No metadata returned from the API.',
                        additionalData: ['symbols_chunk' => $chunk]
                    );
                }

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
        } catch (\Throwable $e) {
            // Catch any exceptions and rethrow a custom exception.
            throw new TryCatchException(
                throwable: $e,
            );
        }
    }
}
