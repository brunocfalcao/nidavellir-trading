<?php

namespace Nidavellir\Trading\Jobs\System\Binance;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Models\ApplicationLog;
use Nidavellir\Trading\Models\Exchange;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;
use Nidavellir\Trading\NidavellirException;
use Throwable;

/**
 * UpsertExchangeAvailableSymbolsJob fetches symbol information
 * from Binance and syncs that data with the local `ExchangeSymbol`
 * model. It filters symbols by USDT margin, updates symbol precision
 * data, and ensures all relevant information is stored in the system.
 */
class UpsertExchangeAvailableSymbolsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 180;

    public ExchangeRESTWrapper $wrapper;

    protected array $symbols;

    private $logBlock;

    /**
     * Initializes the job by setting up the API wrapper with
     * Binance credentials.
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
     * Main function to handle fetching symbols from Binance,
     * filtering for USDT margin symbols, and syncing them
     * with the database.
     */
    public function handle()
    {
        ApplicationLog::withActionCanonical('UpsertExchangeAvailableSymbolsJob.Start')
            ->withDescription('Starting job to sync available Binance symbols')
            ->withBlock($this->logBlock)
            ->saveLog();

        try {
            $mapper = $this->wrapper->mapper;

            // Fetch symbols from Binance API.
            $this->symbols = $mapper->getExchangeInformation();

            ApplicationLog::withActionCanonical('UpsertExchangeAvailableSymbolsJob.SymbolsFetched')
                ->withDescription('Fetched symbols from Binance API')
                ->withReturnData(['symbols' => array_keys($this->symbols)])
                ->withBlock($this->logBlock)
                ->saveLog();

            if (empty($this->symbols)) {
                throw new NidavellirException(
                    title: 'No symbols fetched from Binance',
                    additionalData: ['exchange' => 'binance']
                );
            }

            // Filter symbols to only include USDT margin symbols.
            $this->symbols = $this->filterSymbolsWithUSDTMargin();

            if (empty($this->symbols)) {
                throw new NidavellirException(
                    title: 'No USDT margin symbols found from Binance data',
                    additionalData: ['exchange' => 'binance']
                );
            }

            // Sync or update the exchange symbols in the database.
            $this->syncExchangeSymbols();

            ApplicationLog::withActionCanonical('UpsertExchangeAvailableSymbolsJob.End')
                ->withDescription('Successfully completed syncing Binance symbols')
                ->withBlock($this->logBlock)
                ->saveLog();
        } catch (Throwable $e) {
            ApplicationLog::withActionCanonical('UpsertExchangeAvailableSymbolsJob.Error')
                ->withDescription('Error occurred during syncing Binance symbols')
                ->withReturnData(['error' => $e->getMessage()])
                ->withBlock($this->logBlock)
                ->saveLog();

            throw new NidavellirException(
                originalException: $e,
                title: 'Error occurred during syncing Binance symbols',
                additionalData: ['exchange' => 'binance']
            );
        }
    }

    /**
     * Syncs the fetched symbols with the database by either
     * updating or creating new ExchangeSymbol records.
     */
    protected function syncExchangeSymbols()
    {
        // Get the Binance exchange record from the database.
        $exchange = Exchange::firstWhere('canonical', 'binance');

        if (! $exchange) {
            throw new NidavellirException(
                title: 'Binance exchange record not found',
                additionalData: ['exchange' => 'binance']
            );
        }

        foreach ($this->symbols as $symbolToken => $data) {
            try {
                $tokenData = $this->extractTokenData($data);
                $token = $tokenData['symbol'];

                $exchangeSymbol = ExchangeSymbol::with('symbol')
                    ->whereHas('symbol', function ($query) use ($token) {
                        $query->where('token', $token);
                    })
                    ->where('exchange_id', $exchange->id)
                    ->first();

                $symbol = Symbol::firstWhere('token', $token);

                if ($symbol) {
                    $symbolData = [
                        'symbol_id' => $symbol->id,
                        'exchange_id' => $exchange->id,
                        'precision_price' => $tokenData['precision_price'],
                        'precision_quantity' => $tokenData['precision_quantity'],
                        'precision_quote' => $tokenData['precision_quote'],
                        'tick_size' => $tokenData['tick_size'],
                        'api_symbol_information' => $data,
                    ];

                    if (! $exchangeSymbol) {
                        ExchangeSymbol::updateOrCreate(
                            [
                                'symbol_id' => $symbolData['symbol_id'],
                                'exchange_id' => $exchange->id,
                            ],
                            $symbolData
                        );
                    } else {
                        $exchangeSymbol->update($symbolData);
                    }

                    ApplicationLog::withActionCanonical('UpsertExchangeAvailableSymbolsJob.SymbolUpdated')
                        ->withDescription("Updated symbol: {$token}")
                        ->withReturnData(['symbol' => $token, 'data' => $symbolData])
                        ->withSymbolId($symbol->id)
                        ->withExchangeId($exchange->id)
                        ->withBlock($this->logBlock)
                        ->saveLog();
                }
            } catch (Throwable $e) {
                ApplicationLog::withActionCanonical('UpsertExchangeAvailableSymbolsJob.SymbolSyncError')
                    ->withDescription('Error occurred while syncing symbol')
                    ->withReturnData(['symbol' => $tokenData['symbol'], 'error' => $e->getMessage()])
                    ->withExchangeId($exchange->id)
                    ->withBlock($this->logBlock)
                    ->saveLog();

                throw new NidavellirException(
                    originalException: $e,
                    title: 'Error occurred while syncing symbol: '.$symbolToken,
                    additionalData: ['token' => $tokenData['symbol'], 'symbolData' => $data],
                    loggable: $exchange
                );
            }
        }
    }

    /**
     * Extracts relevant token data (such as precision and
     * tick size) from the symbol information provided by
     * the Binance API.
     */
    private function extractTokenData($item)
    {
        $tickSize = collect($item['filters'])
            ->firstWhere('filterType', 'PRICE_FILTER')['tickSize'] ?? null;

        return [
            'symbol' => $item['baseAsset'],
            'precision_price' => $item['pricePrecision'],
            'precision_quantity' => $item['quantityPrecision'],
            'precision_quote' => $item['quotePrecision'],
            'tick_size' => $tickSize,
        ];
    }

    /**
     * Filters the symbols fetched from Binance to only
     * include those with a 'marginAsset' of 'USDT'.
     */
    private function filterSymbolsWithUSDTMargin()
    {
        return array_filter($this->symbols, function ($symbol) {
            return $symbol['marginAsset'] === 'USDT';
        });
    }
}
