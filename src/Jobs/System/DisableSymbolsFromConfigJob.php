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
        try {
            // Fetch the excluded tokens from the config.
            $excludedSymbols = Config::get('nidavellir.symbols.excluded.tokens', []);

            // If there are excluded symbols, update them to inactive.
            if (! empty($excludedSymbols)) {
                // Disable the symbols in the `symbols` table.
                Symbol::whereIn('token', $excludedSymbols)
                    ->update(['is_active' => false]);

                // Iterate over each excluded token.
                foreach ($excludedSymbols as $token) {
                    // Find the symbol record for the token.
                    $symbol = Symbol::firstWhere('token', $token);

                    // If the symbol exists, disable it.
                    if ($symbol) {
                        $symbol->update(['is_active' => false]);

                        // Find and disable the corresponding ExchangeSymbol record.
                        $exchangeSymbol = ExchangeSymbol::firstWhere('symbol_id', $symbol->id);

                        if ($exchangeSymbol) {
                            $exchangeSymbol->update(['is_active' => false]);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Throw a custom exception if any error occurs during processing.
            throw new NidavellirException($e);
        }
    }
}
