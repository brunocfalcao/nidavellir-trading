<?php

namespace Nidavellir\Trading\Jobs\ApiSystems\Binance;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\ApiSystems\ApiSystemRESTWrapper;
use Nidavellir\Trading\ApiSystems\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exceptions\NotionalAndLeverageNotSyncedException;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Models\ApiSystem;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;

/**
 * UpsertNotionalAndLeverageJob fetches and updates notional and
 * leverage information for symbols on Binance. It synchronizes
 * the leverage data for USDT-margin pairs by updating the
 * corresponding `ExchangeSymbol` records in the database.
 */
class UpsertNotionalAndLeverageJob extends AbstractJob
{
    public ApiSystemRESTWrapper $wrapper;

    /**
     * Constructor to initialize the API wrapper with Binance
     * credentials and generate a UUID block for logging.
     */
    public function __construct()
    {
        $this->wrapper = new ApiSystemRESTWrapper(
            new BinanceRESTMapper(
                credentials: Nidavellir::getSystemCredentials('binance')
            )
        );
    }

    /**
     * Main function to handle fetching and updating notional
     * and leverage data for Binance symbols.
     */
    public function handle()
    {
        try {
            // Retrieve the Binance exchange record from the database.
            $exchange = ApiSystem::firstWhere('canonical', 'binance');

            // Fetch notional and leverage data for all symbols from Binance API.
            $symbols = $this->wrapper
                ->withLoggable($exchange)
                ->getLeverageBrackets();

            if (! $symbols) {
                throw new NotionalAndLeverageNotSyncedException(
                    message: 'No notional and leverage data received',
                    additionalData: ['exchange' => 'binance']
                );
            }

            foreach ($symbols as $symbolData) {
                if (str_ends_with($symbolData['symbol'], 'USDT')) {
                    $token = substr($symbolData['symbol'], 0, -4);
                    $symbol = Symbol::firstWhere('token', $token);

                    if ($symbol) {
                        ExchangeSymbol::where('api_system_id', $exchange->id)
                            ->where('symbol_id', $symbol->id)
                            ->update([
                                'api_notional_and_leverage_symbol_information' => $symbolData,
                            ]);
                    }
                }
            }
            $this->jobPollerInstance->markAsComplete();
        } catch (\Throwable $e) {
            $this->jobPollerInstance->markAsError($e);
            throw new TryCatchException(
                throwable: $e,
            );
        }
    }
}
