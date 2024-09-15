<?php

namespace Nidavellir\Trading\Jobs\System;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Nidavellir\Trading\Models\ApplicationLog;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\NidavellirException;

/**
 * DisableSymbolsFromConfigJob is responsible for disabling
 * symbols based on the exclusion list from the configuration.
 * It updates the status of these symbols in the database to
 * inactive (`is_active = false`).
 *
 * - Fetches excluded tokens from the config.
 * - Updates the `is_active` status to false for excluded symbols.
 * - Disables corresponding `ExchangeSymbol` records.
 */
class DisableSymbolsFromConfigJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // UUID block to group logs together
    private $logBlock;

    /**
     * Constructor for the job. It currently does not require
     * any initialization parameters.
     */
    public function __construct()
    {
        $this->logBlock = Str::uuid(); // Generate a UUID block for log entries
    }

    /**
     * Handle the execution of the job. It fetches the excluded
     * tokens from the config and disables them in both the
     * `symbols` and `exchange_symbols` tables.
     */
    public function handle()
    {
        // Log the start of the job
        ApplicationLog::withActionCanonical('DisableSymbolsFromConfigJob.Start')
            ->withDescription('Starting to disable symbols from config exclusion list')
            ->withBlock($this->logBlock)
            ->saveLog();

        try {
            // Fetch the excluded tokens from the config.
            $excludedSymbols = Config::get('nidavellir.symbols.excluded.tokens', []);

            // Log the excluded symbols fetched
            ApplicationLog::withActionCanonical('DisableSymbolsFromConfigJob.ExcludedSymbolsFetched')
                ->withDescription('Fetched excluded symbols from config')
                ->withReturnData(['excluded_symbols' => $excludedSymbols])
                ->withBlock($this->logBlock)
                ->saveLog();

            // If there are excluded symbols, update them to inactive.
            if (! empty($excludedSymbols)) {
                // Disable the symbols in the `symbols` table.
                Symbol::whereIn('token', $excludedSymbols)
                    ->update(['is_active' => false]);

                // Log symbol deactivation in the symbols table
                ApplicationLog::withActionCanonical('DisableSymbolsFromConfigJob.SymbolsDeactivated')
                    ->withDescription('Deactivated symbols in the symbols table')
                    ->withReturnData(['excluded_symbols' => $excludedSymbols])
                    ->withBlock($this->logBlock)
                    ->saveLog();

                // Iterate over each excluded token.
                foreach ($excludedSymbols as $token) {
                    // Find the symbol record for the token.
                    $symbol = Symbol::firstWhere('token', $token);

                    // If the symbol exists, disable it.
                    if ($symbol) {
                        $symbol->update(['is_active' => false]);

                        // Log individual symbol deactivation
                        ApplicationLog::withActionCanonical('DisableSymbolsFromConfigJob.SymbolDeactivated')
                            ->withDescription("Deactivated symbol: $token")
                            ->withSymbolId($symbol->id)
                            ->withBlock($this->logBlock)
                            ->saveLog();

                        // Find and disable the corresponding ExchangeSymbol record.
                        $exchangeSymbol = ExchangeSymbol::firstWhere('symbol_id', $symbol->id);

                        if ($exchangeSymbol) {
                            $exchangeSymbol->update(['is_active' => false]);

                            // Log ExchangeSymbol deactivation
                            ApplicationLog::withActionCanonical('DisableSymbolsFromConfigJob.ExchangeSymbolDeactivated')
                                ->withDescription("Deactivated ExchangeSymbol for symbol: $token")
                                ->withExchangeSymbolId($exchangeSymbol->id)
                                ->withBlock($this->logBlock)
                                ->saveLog();
                        }
                    }
                }
            }

            // Log the successful completion of the job
            ApplicationLog::withActionCanonical('DisableSymbolsFromConfigJob.End')
                ->withDescription('Successfully disabled symbols based on config')
                ->withBlock($this->logBlock)
                ->saveLog();
        } catch (\Throwable $e) {
            // Log any error during processing
            ApplicationLog::withActionCanonical('DisableSymbolsFromConfigJob.Error')
                ->withDescription('Error occurred while disabling symbols from config')
                ->withReturnData(['error' => $e->getMessage()])
                ->withBlock($this->logBlock)
                ->saveLog();

            // Throw a custom exception if any error occurs during processing.
            throw new NidavellirException($e);
        }
    }
}
