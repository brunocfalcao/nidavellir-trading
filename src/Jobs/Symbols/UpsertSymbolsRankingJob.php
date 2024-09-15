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
 * UpsertSymbolsRankingJob handles fetching the latest symbol
 * rankings from the CoinMarketCap API and updating the ranks
 * of the existing symbols in the database.
 */
class UpsertSymbolsRankingJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 180;

    private $logBlock;

    /**
     * Constructor for the job. Currently, it doesn't require
     * any specific initialization.
     */
    public function __construct()
    {
        $this->logBlock = Str::uuid(); // Generate UUID block for log entries
    }

    /**
     * Handles the job execution to fetch symbol rankings
     * and update them in the database.
     */
    public function handle()
    {
        ApplicationLog::withActionCanonical('UpsertSymbolsRankingJob.Start')
            ->withDescription('Starting job to update symbol rankings from CoinMarketCap')
            ->withBlock($this->logBlock)
            ->saveLog();

        try {
            // Initialize the API wrapper using system credentials.
            $api = new ExchangeRESTWrapper(
                new CoinmarketCapRESTMapper(
                    credentials: Nidavellir::getSystemCredentials('coinmarketcap')
                )
            );

            // Fetch the latest symbol rankings from CoinMarketCap API.
            $symbolsRanking = (array) $api->getSymbolsRanking();

            ApplicationLog::withActionCanonical('UpsertSymbolsRankingJob.SymbolsFetched')
                ->withDescription('Fetched symbols ranking from CoinMarketCap API')
                ->withReturnData(['symbols_ranking' => array_column($symbolsRanking, 'id')])
                ->withBlock($this->logBlock)
                ->saveLog();

            // Retrieve all symbols from the database, keyed by their CoinMarketCap ID.
            $symbols = Symbol::all()->keyBy('coinmarketcap_id');

            // Iterate over the fetched ranking data and update the symbols.
            foreach ($symbolsRanking as $item) {
                $coinmarketcapId = $item['id'];
                $newRank = $item['rank'];

                // Check if the symbol exists in the database and update the rank if needed.
                if (isset($symbols[$coinmarketcapId])) {
                    $symbol = $symbols[$coinmarketcapId];

                    if ($symbol->rank != $newRank || is_null($symbol->rank)) {
                        $symbol->rank = $newRank;
                        $symbol->save();

                        ApplicationLog::withActionCanonical('UpsertSymbolsRankingJob.SymbolUpdated')
                            ->withDescription("Updated rank for symbol: {$symbol->token}")
                            ->withSymbolId($symbol->id)
                            ->withReturnData(['coinmarketcap_id' => $coinmarketcapId, 'new_rank' => $newRank])
                            ->withBlock($this->logBlock)
                            ->saveLog();
                    }
                }
            }

            ApplicationLog::withActionCanonical('UpsertSymbolsRankingJob.End')
                ->withDescription('Successfully completed symbol rank update from CoinMarketCap')
                ->withBlock($this->logBlock)
                ->saveLog();
        } catch (Throwable $e) {
            ApplicationLog::withActionCanonical('UpsertSymbolsRankingJob.Error')
                ->withDescription('Error occurred during symbol rank update')
                ->withReturnData(['error' => $e->getMessage()])
                ->withBlock($this->logBlock)
                ->saveLog();

            throw new NidavellirException(
                originalException: $e,
                title: 'Error occurred while updating symbol ranks',
                additionalData: [
                    'source' => 'CoinmarketCap API',
                ]
            );
        }
    }
}
