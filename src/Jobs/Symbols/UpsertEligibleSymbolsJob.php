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
        $tokens = config('nidavellir.symbols.excluded.tokens');

        // Get min coinmarketcap rank defined on configuration.
        $rank = config('nidavellir.symbols.excluded.min_rank');

        // Iterate exchange symbols and disable/enable them.
        ExchangeSymbol::all()->each(function ($exchangeSymbol) use ($tokens, $rank) {

            // Symbol in config? -- Disable it.
            if (in_array($exchangeSymbol->symbol->token, $tokens)) {
                $exchangeSymbol->update(['is_active' => false]);
            }

            // Symbol not in 25th rank? -- Disable it.
            elseif ($exchangeSymbol->symbol->rank > $rank) {
                $exchangeSymbol->update(['is_active' => false]);
            }

            // Already disabled? Reactivate it.
            elseif (! $exchangeSymbol->is_active) {
                $exchangeSymbol->update(['is_active' => true]);
            }
        });
    }
}
