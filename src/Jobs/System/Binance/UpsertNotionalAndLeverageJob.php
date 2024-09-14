<?php

namespace Nidavellir\Trading\Jobs\System\Binance;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Exceptions\NidavellirException;
use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Models\Exchange;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;
use Throwable;

/**
 * Class: UpsertNotionalAndLeverageJob
 *
 * This job fetches and updates notional and leverage information
 * for symbols on Binance. It synchronizes the leverage data for
 * USDT-margin pairs by updating the corresponding `ExchangeSymbol`
 * records in the database.
 *
 * Important points:
 * - Fetches notional and leverage data for Binance symbols.
 * - Only updates USDT-margin symbols.
 * - Raises a custom exception if data is not received or an error occurs.
 */
class UpsertNotionalAndLeverageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ExchangeRESTWrapper $wrapper;

    /**
     * Initializes the job by setting up the API wrapper
     * with Binance credentials.
     */
    public function __construct()
    {
        $this->wrapper = new ExchangeRESTWrapper(
            new BinanceRESTMapper(
                credentials: Nidavellir::getSystemCredentials('binance')
            )
        );
    }

    /**
     * Main function to handle fetching and updating notional and
     * leverage data for Binance symbols.
     */
    public function handle()
    {
        try {
            /**
             * Retrieve the Binance exchange record from the database.
             */
            $exchange = Exchange::firstWhere('canonical', 'binance');

            if (! $exchange) {
                throw new NidavellirException(
                    title: 'Binance exchange not found',
                    additionalData: ['exchange' => 'binance']
                );
            }

            /**
             * Fetch notional and leverage data for all symbols from the Binance API.
             */
            $symbols = $this->wrapper->getLeverageBrackets();

            if (! $symbols) {
                throw new NidavellirException(
                    title: 'No notional and leverage data received',
                    additionalData: ['exchange' => 'binance']
                );
            }

            /**
             * Iterate over each symbol and update the `ExchangeSymbol`
             * model with the fetched notional and leverage data.
             */
            foreach ($symbols as $symbolData) {
                /**
                 * Only update symbols that have USDT as the margin asset.
                 */
                if (str_ends_with($symbolData['symbol'], 'USDT')) {
                    // Extract the token name (remove 'USDT' suffix)
                    $token = substr($symbolData['symbol'], 0, -4);

                    // Find the corresponding Symbol record in the database
                    $symbol = Symbol::firstWhere('token', $token);

                    if ($symbol) {
                        /**
                         * Update the `ExchangeSymbol` record with the
                         * notional and leverage information.
                         */
                        ExchangeSymbol::where('exchange_id', $exchange->id)
                            ->where('symbol_id', $symbol->id)
                            ->update([
                                'api_notional_and_leverage_symbol_information' => $symbolData,
                            ]);
                    } else {
                        throw new NidavellirException(
                            title: 'Symbol not found for token: '.$token,
                            additionalData: ['symbol' => $symbolData['symbol']]
                        );
                    }
                }
            }
        } catch (Throwable $e) {
            /**
             * Handle any errors by raising a custom exception.
             */
            throw new NidavellirException(
                originalException: $e,
                title: 'Error occurred while updating notional and leverage data for Binance symbols',
                loggable: $exchange ?? null // Pass $exchange if available
            );
        }
    }
}
