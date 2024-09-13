<?php

namespace Nidavellir\Trading\Jobs\Symbols;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Exceptions\UpsertEligibleSymbolException;
use Throwable;

class UpsertEligibleSymbolsJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        //
    }

    public function handle()
    {
        try {
            // Get the excluded tokens from the configuration.
            $excludedTokens = config('nidavellir.symbols.excluded.tokens');

            // Reset is_eligible for all symbols before processing.
            ExchangeSymbol::query()->update(['is_eligible' => false]);

            // Initialize counter for eligible symbols
            $eligibleCount = 0;

            // Fetch exchange symbols that are active and not excluded
            ExchangeSymbol::query()
                ->join('symbols', 'exchange_symbols.symbol_id', '=', 'symbols.id')
                ->where('exchange_symbols.is_active', true)                  // Only consider active symbols
                ->whereNotIn('symbols.token', $excludedTokens)               // Exclude tokens in the exclusion list
                ->orderBy('symbols.rank', 'asc')                             // Order by symbol rank ascending
                ->select('exchange_symbols.*', 'symbols.rank')               // Select necessary columns
                ->each(function ($exchangeSymbol) use (&$eligibleCount) {
                    if ($eligibleCount < 20) {
                        // Mark symbol as eligible
                        $exchangeSymbol->update(['is_eligible' => true]);
                        $eligibleCount++;
                    }
                });

            // If we failed to update at least one eligible symbol
            if ($eligibleCount === 0) {
                throw new UpsertEligibleSymbolException(message: 'No eligible symbols updated.');
            }
        } catch (Throwable $e) {
            // Handle the exception and pass it to our custom exception
            throw new UpsertEligibleSymbolException($e);
        }
    }
}
