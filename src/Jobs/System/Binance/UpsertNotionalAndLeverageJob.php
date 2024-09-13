<?php

namespace Nidavellir\Trading\Jobs\System\Binance;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Exceptions\NotionalAndLeverageNotUpdatedException;
use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Models\Exchange;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;
use Throwable;

class UpsertNotionalAndLeverageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ExchangeRESTWrapper $wrapper;

    public function __construct()
    {
        $this->wrapper = new ExchangeRESTWrapper(
            new BinanceRESTMapper(
                credentials: Nidavellir::getSystemCredentials('binance')
            )
        );
    }

    public function handle()
    {
        try {
            // Get Binance exchange entry
            $exchange = Exchange::firstWhere('canonical', 'binance');

            if (! $exchange) {
                throw new NotionalAndLeverageNotUpdatedException(message: 'Binance exchange not found.');
            }

            // Obtain all notional and leverage data for the symbols
            $symbols = $this->wrapper->getLeverageBrackets();

            if (! $symbols) {
                throw new NotionalAndLeverageNotUpdatedException(message: 'No notional and leverage data received.');
            }

            // Update each symbol with the notional and leverage data
            foreach ($symbols as $symbolData) {
                // Only update USDT-margin assets
                if (str_ends_with($symbolData['symbol'], 'USDT')) {
                    $token = substr($symbolData['symbol'], 0, -4);

                    $symbol = Symbol::firstWhere('token', $token);

                    if ($symbol) {
                        // Update only if the exchange symbol exists
                        ExchangeSymbol::where('exchange_id', $exchange->id)
                            ->where('symbol_id', $symbol->id)
                            ->update([
                                'api_notional_and_leverage_symbol_information' => $symbolData,
                            ]);
                    }
                }
            }
        } catch (Throwable $e) {
            // Raise a custom exception if something goes wrong
            throw new NotionalAndLeverageNotUpdatedException(
                $e
            );
        }
    }
}
