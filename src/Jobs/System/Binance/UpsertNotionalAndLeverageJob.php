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
use Nidavellir\Trading\Models\ApplicationLog;
use Nidavellir\Trading\Nidavellir;
use Nidavellir\Trading\NidavellirException;
use Illuminate\Support\Str;
use Throwable;

/**
 * UpsertNotionalAndLeverageJob fetches and updates notional and
 * leverage information for symbols on Binance. It synchronizes
 * the leverage data for USDT-margin pairs by updating the
 * corresponding `ExchangeSymbol` records in the database.
 */
class UpsertNotionalAndLeverageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 180;

    public ExchangeRESTWrapper $wrapper;

    private $logBlock;

    /**
     * Constructor to initialize the API wrapper with Binance
     * credentials and generate a UUID block for logging.
     */
    public function __construct()
    {
        $this->wrapper = new ExchangeRESTWrapper(
            new BinanceRESTMapper(
                credentials: Nidavellir::getSystemCredentials('binance')
            )
        );

        $this->logBlock = Str::uuid(); // Generate UUID block for log entries
    }

    /**
     * Main function to handle fetching and updating notional
     * and leverage data for Binance symbols.
     */
    public function handle()
    {
        ApplicationLog::withActionCanonical('UpsertNotionalAndLeverageJob.Start')
            ->withDescription('Starting job to update notional and leverage data for Binance symbols')
            ->withBlock($this->logBlock)
            ->saveLog();

        try {
            // Retrieve the Binance exchange record from the database.
            $exchange = Exchange::firstWhere('canonical', 'binance');

            if (! $exchange) {
                throw new NidavellirException(
                    title: 'Binance exchange not found',
                    additionalData: ['exchange' => 'binance']
                );
            }

            // Fetch notional and leverage data for all symbols from Binance API.
            $symbols = $this->wrapper->getLeverageBrackets();

            ApplicationLog::withActionCanonical('UpsertNotionalAndLeverageJob.SymbolsFetched')
                ->withDescription('Fetched symbols from Binance API')
                ->withReturnData(['symbols' => $symbols])
                ->withExchangeId($exchange->id)
                ->withBlock($this->logBlock)
                ->saveLog();

            if (! $symbols) {
                throw new NidavellirException(
                    title: 'No notional and leverage data received',
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

                        ApplicationLog::withActionCanonical('UpsertNotionalAndLeverageJob.SymbolUpdated')
                            ->withDescription("Updated notional and leverage data for symbol: {$symbol->token}")
                            ->withReturnData(['symbol' => $symbol->token, 'data' => $symbolData])
                            ->withSymbolId($symbol->id)
                            ->withExchangeId($exchange->id)
                            ->withBlock($this->logBlock)
                            ->saveLog();
                    }
                }
            }

            ApplicationLog::withActionCanonical('UpsertNotionalAndLeverageJob.End')
                ->withDescription('Successfully completed updating notional and leverage data')
                ->withBlock($this->logBlock)
                ->saveLog();
        } catch (Throwable $e) {
            ApplicationLog::withActionCanonical('UpsertNotionalAndLeverageJob.Error')
                ->withDescription('Error occurred while updating notional and leverage data')
                ->withReturnData(['error' => $e->getMessage()])
                ->withExchangeId($exchange->id ?? null) // Log exchange ID if available
                ->withBlock($this->logBlock)
                ->saveLog();

            throw new NidavellirException(
                originalException: $e,
                title: 'Error occurred while updating notional and leverage data for Binance symbols',
                loggable: $exchange ?? null
            );
        }
    }
}
