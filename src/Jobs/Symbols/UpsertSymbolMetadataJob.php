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
use Nidavellir\Trading\Models\ApplicationLog;
use Nidavellir\Trading\Nidavellir;
use Nidavellir\Trading\NidavellirException;
use Illuminate\Support\Str;
use Throwable;

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
class UpsertSymbolMetadataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 180;
    private $logBlock;

    /**
     * Constructor for generating a UUID block for logging.
     */
    public function __construct()
    {
        $this->logBlock = Str::uuid(); // Generate UUID block for log entries
    }

    /**
     * Handle the job to upsert symbol metadata.
     *
     * Fetches and updates metadata for symbols missing metadata
     * (e.g., image or description).
     */
    public function handle()
    {
        ApplicationLog::withActionCanonical('UpsertSymbolMetadataJob.Start')
            ->withDescription('Starting job to upsert symbol metadata')
            ->withBlock($this->logBlock)
            ->saveLog();

        try {
            // Initialize API wrapper for CoinMarketCap using system credentials.
            $api = new ExchangeRESTWrapper(
                new CoinmarketCapRESTMapper(
                    credentials: Nidavellir::getSystemCredentials('coinmarketcap')
                )
            );

            // Get all symbols that are missing metadata (image_url, description).
            $symbols = Symbol::whereNull('image_url')
                ->orWhereNull('description')
                ->pluck('coinmarketcap_id')
                ->toArray();

            ApplicationLog::withActionCanonical('UpsertSymbolMetadataJob.SymbolsFetched')
                ->withDescription('Fetched symbols missing metadata')
                ->withReturnData(['symbols_count' => count($symbols)])
                ->withBlock($this->logBlock)
                ->saveLog();

            // Process symbols in chunks to avoid large requests (up to 100 symbols per chunk).
            foreach (array_chunk($symbols, 100) as $chunk) {
                $symbolList = implode(',', $chunk);

                // Fetch metadata for the current chunk of symbols.
                $cryptoDataList = (array) $api->withOptions(['ids' => $symbolList])
                    ->getSymbolsMetadata();

                // Log when processing each chunk.
                ApplicationLog::withActionCanonical('UpsertSymbolMetadataJob.ProcessingChunk')
                    ->withDescription('Processing chunk of symbols for metadata')
                    ->withReturnData(['chunk_size' => count($chunk)])
                    ->withBlock($this->logBlock)
                    ->saveLog();

                // Throw an exception if no metadata is returned.
                if (empty($cryptoDataList)) {
                    ApplicationLog::withActionCanonical('UpsertSymbolMetadataJob.NoMetadata')
                        ->withDescription('No metadata returned from API for the chunk')
                        ->withReturnData(['symbols_chunk' => $chunk])
                        ->withBlock($this->logBlock)
                        ->saveLog();

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

                ApplicationLog::withActionCanonical('UpsertSymbolMetadataJob.ChunkProcessed')
                    ->withDescription('Successfully processed metadata for a chunk of symbols')
                    ->withReturnData(['processed_count' => count($cryptoDataList)])
                    ->withBlock($this->logBlock)
                    ->saveLog();
            }

            ApplicationLog::withActionCanonical('UpsertSymbolMetadataJob.End')
                ->withDescription('Successfully completed symbol metadata upsert job')
                ->withBlock($this->logBlock)
                ->saveLog();
        } catch (Throwable $e) {
            ApplicationLog::withActionCanonical('UpsertSymbolMetadataJob.Error')
                ->withDescription('Error occurred during symbol metadata upsert')
                ->withReturnData(['error' => $e->getMessage()])
                ->withBlock($this->logBlock)
                ->saveLog();

            // Catch any exceptions and rethrow a custom exception.
            throw new NidavellirException(
                originalException: $e,
                title: 'Error occurred while updating symbol metadata.',
                additionalData: []
            );
        }
    }
}
