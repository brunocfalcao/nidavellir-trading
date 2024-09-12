<?php

namespace Nidavellir\Trading\Jobs\System\Binance;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Models\Exchange;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;

class UpsertNotionalAndLeverageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public BinanceRESTMapper $mapper;

    public function __construct()
    {
        $this->mapper = (new ExchangeRESTWrapper(
            new BinanceRESTMapper(
                credentials: Nidavellir::getSystemCredentials('binance')
            )
        ))->mapper;
    }

    public function handle()
    {
        $exchange = Exchange::firstWhere('canonical', 'binance');

        // Obtain all notional and leverage data for the symbols.
        $symbols = $this->mapper->getLeverageBrackets();

        // Update each symbol with this information.
        foreach ($symbols as $symbolData) {
            // Only for USDT margin assets.
            if (str_ends_with($symbolData['symbol'], 'USDT')) {
                $symbol = Symbol::firstWhere('token', substr($symbolData['symbol'], 0, -4));

                if ($symbol) {
                    ExchangeSymbol::where('exchange_id', $exchange->id)
                        ->where('symbol_id', $symbol->id)
                        ->update([
                            'api_notional_and_leverage_symbol_information' => $symbolData]);
                }
            }
        }
    }
}
