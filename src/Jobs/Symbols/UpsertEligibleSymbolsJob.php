<?php

namespace Nidavellir\Trading\Jobs\Symbols;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Exceptions\NidavellirException;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Symbol;
use Throwable;

/**
 * Class: UpsertEligibleSymbolsJob
 *
 * This class is responsible for determining and updating
 * eligible cryptocurrency symbols based on certain criteria.
 * It resets the eligibility status for all symbols, then
 * identifies and updates the top 20 eligible symbols, excluding
 * those in the configured exclusion list.
 *
 * Important points:
 * - Resets `is_eligible` to false for all symbols before processing.
 * - Excludes tokens based on the exclusion list from configuration.
 * - Only considers active symbols and marks up to 20 as eligible.
 */
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
            /**
             * Fetch the list of excluded tokens from
             * the Nidavellir configuration file.
             */
            $excludedTokens = config('nidavellir.symbols.excluded.tokens');

            /**
             * Reset the `is_eligible` flag to false
             * for all symbols before starting the process.
             */
            ExchangeSymbol::query()->update(['is_eligible' => false]);

            /**
             * Initialize a counter to track the number
             * of eligible symbols being updated.
             */
            $eligibleCount = 0;

            /**
             * Perform a query that joins `exchange_symbols`
             * and `symbols` tables. It filters for active
             * symbols and excludes those in the exclusion list.
             * The result is sorted by rank.
             *
             * The query iterates over the results, updating up to
             * 20 eligible symbols, marking them as `is_eligible = true`.
             */
            ExchangeSymbol::query()
                ->join('symbols', 'exchange_symbols.symbol_id', '=', 'symbols.id')
                ->where('exchange_symbols.is_active', true)  // Only active symbols
                ->whereNotIn('symbols.token', $excludedTokens) // Excluded tokens
                ->orderBy('symbols.rank', 'asc')  // Sort by rank (ascending)
                ->select('exchange_symbols.*', 'symbols.rank')  // Select necessary columns
                ->each(function ($exchangeSymbol) use (&$eligibleCount) {
                    if ($eligibleCount < 20) {
                        /**
                         * Mark the symbol as eligible
                         * by setting `is_eligible` to true.
                         */
                        $exchangeSymbol->update(['is_eligible' => true]);
                        $eligibleCount++;
                    }
                });

            /**
             * If no eligible symbols are updated, throw a custom
             * exception to handle this scenario.
             */
            if ($eligibleCount === 0) {
                throw new NidavellirException(
                    title: 'No eligible symbols updated.',
                    additionalData: ['excludedTokens' => $excludedTokens]
                );
            }
        } catch (Throwable $e) {
            /**
             * Handle any exceptions that occur during the process
             * and throw a custom exception.
             */
            throw new NidavellirException(
                originalException: $e,
                title: 'Error occurred while updating eligible symbols.',
                additionalData: []
            );
        }
    }
}
