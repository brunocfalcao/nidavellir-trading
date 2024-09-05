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
    use Batchable,
        Dispatchable,
        InteractsWithQueue,
        Queueable,
        SerializesModels;

    public function __construct()
    {
        //
    }

    public function handle()
    {
        // Get non-elligible symbols from the configuration.
        $excludedTokens = config('nidavellir.symbols.excluded.tokens');

        // Get min coinmarketcap rank defined on configuration.
        $rank = config('nidavellir.symbols.excluded.max_rank');

        // All symbols deserve a new chance.
        ExchangeSymbol::query()->update([
            'is_active' => true,
            'is_eligible' => true,
        ]);

        // Iterate exchange symbols and disable/eligible them.
        ExchangeSymbol::all()->each(function ($exchangeSymbol) use ($excludedTokens, $rank) {

            // Symbol in exclusions config? -- Disable it.
            if (in_array($exchangeSymbol->symbol->token, $excludedTokens)) {
                $exchangeSymbol->update([
                    'is_active' => false,
                    'is_eligible' => false]);
            }

            // Symbol not in 25th rank? -- Not eligible.
            elseif ($exchangeSymbol->symbol->rank > $rank) {
                $exchangeSymbol->update([
                    'is_eligible' => false]);
            }
        });
    }
}
