<?php

namespace Nidavellir\Trading\Jobs\Symbols;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\NidavellirException;
use Throwable;

/**
 * UpsertEligibleSymbolsJob determines and updates eligible
 * cryptocurrency symbols based on certain criteria. It resets
 * the eligibility status for all symbols, then identifies and
 * updates the top 20 eligible symbols, excluding those in the
 * configured exclusion list.
 *
 * - Resets `is_eligible` to false for all symbols.
 * - Excludes tokens based on the exclusion list.
 * - Marks up to 20 active symbols as eligible.
 */
class UpsertEligibleSymbolsJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 180;

    private $logBlock;

    /**
     * Constructor generates a UUID block for logging.
     */
    public function __construct()
    {
        $this->logBlock = Str::uuid(); // Generate UUID block for log entries
    }

    /**
     * Main function to handle the updating of eligible symbols.
     */
    public function handle()
    {
        try {
            // Fetch the list of excluded tokens from the configuration file.
            $excludedTokens = Symbol::where('is_active', false)->pluck('id');

            // Reset the `is_eligible` flag to false for all symbols.
            ExchangeSymbol::query()->update(['is_eligible' => false]);

            // Initialize a counter to track the number of eligible symbols.
            $eligibleCount = 0;

            // Perform a query to filter active symbols, exclude tokens, and sort by rank.
            ExchangeSymbol::query()
                ->join('symbols', 'exchange_symbols.symbol_id', '=', 'symbols.id')
                ->where('exchange_symbols.is_active', true)
                ->whereNotIn('symbols.token', $excludedTokens)
                ->orderBy('symbols.rank', 'asc')
                ->select('exchange_symbols.*', 'symbols.rank')
                ->each(function ($exchangeSymbol) use (&$eligibleCount) {
                    if ($eligibleCount < 20) {
                        // Mark the symbol as eligible by setting `is_eligible` to true.
                        $exchangeSymbol->update(['is_eligible' => true]);
                        $eligibleCount++;
                    }
                });

            // If no eligible symbols are updated, throw a custom exception.
            if ($eligibleCount === 0) {
                throw new NidavellirException(
                    title: 'No eligible symbols updated.',
                    additionalData: ['excludedTokens' => $excludedTokens]
                );
            }
        } catch (Throwable $e) {
            // Handle any exceptions and throw a custom exception.
            throw new NidavellirException(
                originalException: $e,
                title: 'Error occurred while updating eligible symbols.',
                additionalData: []
            );
        }
    }
}
