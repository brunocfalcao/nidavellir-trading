<?php

namespace Nidavellir\Trading\Jobs\Symbols;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Exceptions\NidavellirException;
use Nidavellir\Trading\Exchanges\CoinmarketCap\CoinmarketCapRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;
use Throwable;

/**
 * Class: UpsertSymbolMetadataJob
 *
 * This class is responsible for fetching and updating
 * metadata for cryptocurrency symbols that have missing
 * information, such as image URLs, descriptions, or websites.
 *
 * The job is dispatched as a queued task to avoid overloading
 * the system with API calls. It interacts with the CoinMarketCap
 * API through a REST wrapper to retrieve symbol metadata in
 * batches, processing up to 100 symbols at a time.
 *
 * Important points:
 * - It only processes symbols with null values for image,
 * description, or website.
 * - Uses custom exception handling (SymbolsMetadataNotUpdatedException)
 * to manage errors.
 * - Updates missing metadata efficiently using array chunks.
 */
class UpsertSymbolMetadataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Handle the job to upsert symbol metadata.
     *
     * Fetches and updates metadata for symbols
     * missing metadata (e.g., image or description)
     */
    public function handle()
    {
        try {
            /**
             * Initialize API wrapper for CoinMarketCap.
             *
             * Uses the credentials from Nidavellir
             * system configuration.
             */
            $api = new ExchangeRESTWrapper(
                new CoinmarketCapRESTMapper(
                    credentials: Nidavellir::getSystemCredentials('coinmarketcap')
                )
            );

            /**
             * Get all symbols that are missing metadata.
             *
             * It checks for null values in image_url
             * and description columns.
             */
            $symbols = Symbol::whereNull('image_url')
                ->orWhereNull('description')
                ->pluck('coinmarketcap_id')
                ->toArray();

            /**
             * If no symbols are found, throw an exception.
             */
            if (empty($symbols)) {
                throw new NidavellirException(
                    title: 'No symbols with missing metadata found.',
                    additionalData: []
                );
            }

            /**
             * Process symbols in chunks to avoid large requests.
             *
             * Each chunk contains up to 100 symbols.
             */
            foreach (array_chunk($symbols, 100) as $chunk) {
                $symbolList = implode(',', $chunk);

                /**
                 * Fetch metadata for the current chunk of symbols.
                 *
                 * Calls the external API to retrieve metadata
                 * like logo, name, website, and description.
                 */
                $cryptoDataList = (array) $api->withOptions(['ids' => $symbolList])
                    ->getSymbolsMetadata();

                /**
                 * Throw an exception if no metadata is returned.
                 */
                if (empty($cryptoDataList)) {
                    throw new NidavellirException(
                        title: 'No metadata returned from the API.',
                        additionalData: ['symbols_chunk' => $chunk]
                    );
                }

                /**
                 * Update symbols with the fetched metadata.
                 *
                 * For each symbol, it updates the name, website,
                 * image_url, and description if they are missing.
                 */
                foreach ($cryptoDataList as $cryptoId => $cryptoData) {
                    $imageUrl = $cryptoData['logo'] ?? null;
                    $name = $cryptoData['name'] ?? null;
                    $website = $cryptoData['urls']['website'][0] ?? null;
                    $description = $cryptoData['description'] ?? null;

                    /**
                     * Only update the symbol if its metadata
                     * fields are null.
                     */
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
            /**
             * Catch any exceptions and rethrow a custom exception.
             *
             * This handles all errors during the process.
             */
            throw new NidavellirException(
                originalException: $e,
                title: 'Error occurred while updating symbol metadata.',
                additionalData: []
            );
        }
    }
}
