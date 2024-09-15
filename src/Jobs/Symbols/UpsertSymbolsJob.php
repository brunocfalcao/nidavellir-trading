<?php

namespace Nidavellir\Trading\Jobs\Symbols;

use Illuminate\Bus\Batchable;
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
 * UpsertSymbolsJob handles fetching symbols from CoinMarketCap
 * API and upserts them into the database. It allows limiting
 * the number of symbols fetched and performs bulk insert/update
 * for symbols to optimize database operations.
 */
class UpsertSymbolsJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 180;

    private ?int $limit;
    private $logBlock;

    /**
     * Constructor to initialize the job with an optional
     * limit for the number of symbols to fetch.
     */
    public function __construct(?int $limit = null)
    {
        // Set the limit for fetching symbols.
        $this->limit = $limit;
        $this->logBlock = Str::uuid(); // Generate UUID block for log entries
    }

    /**
     * Handle the job execution, fetching symbols and
     * performing the upsert operation.
     */
    public function handle()
    {
        ApplicationLog::withActionCanonical('UpsertSymbolsJob.Start')
            ->withDescription('Starting job to upsert symbols from CoinMarketCap')
            ->withReturnData(['limit' => $this->limit])
            ->withBlock($this->logBlock)
            ->saveLog();

        try {
            // Initialize the CoinMarketCap API wrapper using system credentials.
            $api = new ExchangeRESTWrapper(
                new CoinmarketCapRESTMapper(
                    credentials: Nidavellir::getSystemCredentials('coinmarketcap')
                )
            );

            // Set API options including the limit for the number of symbols to fetch.
            if ($this->limit) {
                $api->withOptions(['limit' => $this->limit]);
            }

            // Fetch the symbol data from the CoinMarketCap API.
            $data = $api->getSymbols();

            ApplicationLog::withActionCanonical('UpsertSymbolsJob.SymbolsFetched')
                ->withDescription('Fetched symbols from CoinMarketCap API')
                ->withReturnData(['fetched_count' => count($data)])
                ->withBlock($this->logBlock)
                ->saveLog();

            // If no data is returned, throw an exception.
            if (! $data) {
                ApplicationLog::withActionCanonical('UpsertSymbolsJob.NoSymbolsFetched')
                    ->withDescription('No symbols fetched from CoinMarketCap API')
                    ->withBlock($this->logBlock)
                    ->saveLog();

                throw new NidavellirException(
                    title: 'No symbols fetched from CoinMarketCap API',
                    additionalData: ['limit' => $this->limit]
                );
            }

            // Prepare an array for bulk symbol updates.
            $symbolUpdates = [];

            // Iterate through the API data and collect symbol information for upserting.
            foreach ($data as $item) {
                $symbolUpdates[] = [
                    'coinmarketcap_id' => $item['id'],
                    'name' => $item['name'],
                    'token' => $item['symbol'],
                    'updated_at' => now(),
                ];
            }

            // Perform bulk upsert using Laravel's upsert method.
            Symbol::upsert(
                $symbolUpdates,
                ['coinmarketcap_id'],
                ['name', 'token', 'updated_at']
            );

            ApplicationLog::withActionCanonical('UpsertSymbolsJob.SymbolsUpserted')
                ->withDescription('Symbols upserted into the database')
                ->withReturnData(['upserted_count' => count($symbolUpdates)])
                ->withBlock($this->logBlock)
                ->saveLog();

            ApplicationLog::withActionCanonical('UpsertSymbolsJob.End')
                ->withDescription('Successfully completed symbol upsert job')
                ->withBlock($this->logBlock)
                ->saveLog();
        } catch (Throwable $e) {
            ApplicationLog::withActionCanonical('UpsertSymbolsJob.Error')
                ->withDescription('Error occurred during symbol upsert')
                ->withReturnData(['error' => $e->getMessage()])
                ->withBlock($this->logBlock)
                ->saveLog();

            throw new NidavellirException(
                originalException: $e,
                title: 'Error occurred during upsert of symbols',
                additionalData: ['limit' => $this->limit]
            );
        }
    }
}
