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

class UpsertEligibleSymbolsJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        //
    }

    public function handle()
    {
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
    }
}
