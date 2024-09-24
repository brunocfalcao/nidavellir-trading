<?php

namespace Nidavellir\Trading\Jobs\System\Binance;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\ApiSystems\Binance\BinanceRESTMapper;
use Nidavellir\Trading\ApiSystems\ExchangeRESTWrapper;
use Nidavellir\Trading\Exceptions\ExchangeSymbolNotSyncedException;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Models\ApiSystem;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;

/**
 * UpsertExchangeAvailableSymbolsJob fetches symbol information
 * from Binance and syncs that data with the local `ExchangeSymbol`
 * model. It filters symbols by USDT margin, updates symbol precision
 * data, and ensures all relevant information is stored in the system.
 */
class UpsertExchangeAvailableSymbolsJob extends AbstractJob
{
    public ExchangeRESTWrapper $wrapper;

    protected array $symbols;

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
    }

    /**
     * Main function to handle fetching symbols from Binance,
     * filtering for USDT margin symbols, and syncing them
     * with the database.
     */
    public function handle()
    {
        try {
            $mapper = $this->wrapper->mapper;

            // Fetch symbols from Binance API.
            $this->symbols = $mapper
                ->withLoggable(ApiSystem::find(1))
                ->getExchangeInformation()['symbols'];

            if (empty($this->symbols)) {
                throw new ExchangeSymbolNotSyncedException(
                    message: 'No symbols fetched from Binance',
                    additionalData: ['exchange' => 'binance']
                );
            }

            // Filter symbols to only include USDT margin symbols.
            $this->symbols = $this->filterSymbolsWithUSDTMargin();

            if (empty($this->symbols)) {
                throw new ExchangeSymbolNotSyncedException(
                    message: 'No USDT margin symbols found from Binance data',
                    additionalData: ['exchange' => 'binance']
                );
            }

            // Sync or update the exchange symbols in the database.
            $this->syncExchangeSymbols();
        } catch (\Throwable $e) {
            throw new TryCatchException(
                throwable: $e,
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
        $exchange = ApiSystem::firstWhere('canonical', 'binance');

        if (! $exchange) {
            throw new ExchangeSymbolNotSyncedException(
                message: 'Binance exchange record not found',
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
                    ->where('api_system_id', $exchange->id)
                    ->first();

                $symbol = Symbol::firstWhere('token', $token);

                if ($symbol) {
                    $symbolData = [
                        'symbol_id' => $symbol->id,
                        'api_system_id' => $exchange->id,
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
                                'api_system_id' => $exchange->id,
                            ],
                            $symbolData
                        );
                    } else {
                        $exchangeSymbol->update($symbolData);
                    }
                }
            } catch (\Throwable $e) {
                throw new TryCatchException(
                    throwable: $e,
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
