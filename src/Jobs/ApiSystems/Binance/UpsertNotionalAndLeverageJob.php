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

class UpsertNotionalAndLeverageJob extends AbstractJob
{
    public ApiSystemRESTWrapper $wrapper;

    public function __construct()
    {
        $this->wrapper = new ApiSystemRESTWrapper(
            new BinanceRESTMapper(
                credentials: Nidavellir::getSystemCredentials('binance')
            )
        );
    }

    public function handle()
    {
        try {
            $exchange = ApiSystem::firstWhere('canonical', 'binance');

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
                        ExchangeSymbol::where('exchange_id', $exchange->id)
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
            throw new TryCatchException(throwable: $e);
        }
    }
}
