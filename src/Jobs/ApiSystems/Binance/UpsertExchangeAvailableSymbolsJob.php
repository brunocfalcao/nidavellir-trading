<?php

namespace Nidavellir\Trading\Jobs\ApiSystems\Binance;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\ApiSystems\ApiSystemRESTWrapper;
use Nidavellir\Trading\ApiSystems\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exceptions\ExchangeSymbolNotSyncedException;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Models\ApiSystem;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;

/**
 * UpsertExchangeAvailableSymbolsJob fetches and synchronizes Binance symbols
 * with the local database. It ensures that the symbols available in Binance
 * are up-to-date, creating or updating records in the `exchange_symbols` table.
 *
 * Important points:
 * - Fetches exchange information from Binance.
 * - Filters out symbols with USDT as the margin asset.
 * - Inserts or updates symbols based on fetched data.
 */
class UpsertExchangeAvailableSymbolsJob extends AbstractJob
{
    // Instance to interact with the Binance API system.
    public ApiSystemRESTWrapper $wrapper;

    // Array containing the symbols fetched from Binance.
    protected array $symbols;

    /**
     * Constructor initializes the API wrapper for Binance using
     * system credentials.
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
     * Main method to handle fetching and synchronizing Binance symbols.
     * It fetches symbols, filters them based on USDT margin, and then
     * synchronizes them with the local database.
     */
    public function handle()
    {
        try {
            // Fetch symbol data from Binance using the wrapper's mapper.
            $mapper = $this->wrapper->mapper;
            $this->symbols = $mapper
                ->withLoggable(ApiSystem::find(1))
                ->getExchangeInformation()['symbols'];

            // Check if symbols were fetched successfully.
            if (empty($this->symbols)) {
                throw new ExchangeSymbolNotSyncedException(
                    message: 'No symbols fetched from Binance',
                    additionalData: ['exchange' => 'binance']
                );
            }

            // Filter symbols to include only those with USDT margin.
            $this->symbols = $this->filterSymbolsWithUSDTMargin();

            // If no USDT margin symbols, throw an exception.
            if (empty($this->symbols)) {
                throw new ExchangeSymbolNotSyncedException(
                    message: 'No USDT margin symbols found from Binance data',
                    additionalData: ['exchange' => 'binance']
                );
            }

            // Synchronize the filtered symbols with the local database.
            $this->syncExchangeSymbols();
        } catch (\Throwable $e) {
            throw new TryCatchException(throwable: $e);
        }
    }

    /**
     * Synchronizes the fetched and filtered Binance symbols with the
     * local database by creating or updating ExchangeSymbol records.
     */
    protected function syncExchangeSymbols()
    {
        // Retrieve the Binance exchange record from the database.
        $exchange = ApiSystem::firstWhere('canonical', 'binance');

        // Throw an exception if the Binance exchange record is not found.
        if (! $exchange) {
            throw new ExchangeSymbolNotSyncedException(
                message: 'Binance exchange record not found',
                additionalData: ['exchange' => 'binance']
            );
        }

        // Iterate over each symbol fetched from Binance.
        foreach ($this->symbols as $symbolToken => $data) {
            try {
                // Extract detailed data for the token.
                $tokenData = $this->extractTokenData($data);
                $token = $tokenData['symbol'];

                // Check if the symbol already exists in ExchangeSymbol.
                $exchangeSymbol = ExchangeSymbol::with('symbol')
                    ->whereHas('symbol', function ($query) use ($token) {
                        $query->where('token', $token);
                    })
                    ->where('exchange_id', $exchange->id)
                    ->first();

                // Find the symbol in the Symbol model.
                $symbol = Symbol::firstWhere('token', $token);

                // If the symbol exists, create or update its ExchangeSymbol record.
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

                    // Create or update the ExchangeSymbol record.
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
                }
            } catch (\Throwable $e) {
                throw new TryCatchException(throwable: $e);
            }
        }
    }

    /**
     * Extracts relevant token data from the Binance API response item.
     * Includes details like precision and tick size.
     */
    private function extractTokenData($item)
    {
        // Extract the tick size from the item's filters.
        $tickSize = collect($item['filters'])
            ->firstWhere('filterType', 'PRICE_FILTER')['tickSize'] ?? null;

        return [
            'symbol' => $item['baseAsset'], // Base asset of the symbol.
            'precision_price' => $item['pricePrecision'], // Price precision.
            'precision_quantity' => $item['quantityPrecision'], // Quantity precision.
            'precision_quote' => $item['quotePrecision'], // Quote precision.
            'tick_size' => $tickSize, // Tick size for the symbol.
        ];
    }

    /**
     * Filters the fetched symbols to include only those with USDT as
     * their margin asset, ensuring only USDT-margined symbols are processed.
     */
    private function filterSymbolsWithUSDTMargin()
    {
        // Return only symbols where the margin asset is USDT.
        return array_filter($this->symbols, function ($symbol) {
            return $symbol['marginAsset'] === 'USDT';
        });
    }
}
