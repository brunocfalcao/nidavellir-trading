<?php

namespace Nidavellir\Trading\Jobs\ApiSystems\Binance;

use Nidavellir\Trading\ApiSystems\ApiSystemRESTWrapper;
use Nidavellir\Trading\ApiSystems\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Jobs\ApiJobFoundations\BinanceApiJob;
use Nidavellir\Trading\Models\ApiSystem;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;

/**
 * UpsertNotionalAndLeverageJob synchronizes notional and leverage data
 * from Binance with the local database for each symbol traded in USDT.
 * This job ensures that leverage brackets are accurately stored in
 * the `exchange_symbols` table for efficient trading operations.
 *
 * Important points:
 * - Fetches leverage brackets from Binance API.
 * - Filters symbols traded with USDT pairs.
 * - Updates the `api_notional_and_leverage_symbol_information` field.
 */
class UpsertNotionalAndLeverageJob extends BinanceApiJob
{
    // Implement the generalized compute method.
    protected function compute()
    {
        // Initialize the Binance API Wrapper.
        $api = new ApiSystemRESTWrapper(
            new BinanceRESTMapper(
                credentials: Nidavellir::getSystemCredentials('binance')
            )
        );

        // Retrieve the Binance exchange record from the database
        $exchange = ApiSystem::firstWhere('canonical', 'binance');

        // Fetch leverage brackets from Binance
        $symbols = $api
            ->withLoggable($exchange)
            ->getLeverageBrackets();

        // Check if leverage data was fetched successfully
        if (empty($symbols)) {
            throw new NotionalAndLeverageNotSyncedException(
                message: 'No notional and leverage data received',
                additionalData: ['exchange' => 'binance']
            );
        }

        // Iterate over each symbol and update notional and leverage data
        foreach ($symbols as $symbolData) {
            // Only process symbols ending with 'USDT'
            if (str_ends_with($symbolData['symbol'], 'USDT')) {
                $token = substr($symbolData['symbol'], 0, -4);
                $symbol = Symbol::firstWhere('token', $token);

                // Update ExchangeSymbol with notional and leverage information
                if ($symbol) {
                    ExchangeSymbol::where('exchange_id', $exchange->id)
                        ->where('symbol_id', $symbol->id)
                        ->update([
                            'api_notional_and_leverage_symbol_information' => $symbolData,
                        ]);
                }
            }
        }
    }
}
