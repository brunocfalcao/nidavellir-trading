<?php

namespace Nidavellir\Trading\Jobs\ApiSystems\Binance;

use Nidavellir\Trading\Nidavellir;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Models\ApiSystem;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\ApiSystems\ApiSystemRESTWrapper;
use Nidavellir\Trading\ApiSystems\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Jobs\ApiJobFoundations\BinanceApiJob;
use Nidavellir\Trading\Exceptions\ExchangeSymbolNotSyncedException;

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
class UpsertExchangeAvailableSymbolsJob extends BinanceApiJob
{
    protected array $symbols = [];

    /**
     * Execute the main logic to fetch and synchronize Binance symbols.
     */
    protected function executeApiLogic()
    {
        // Initialize the Binance API Wrapper.
        $api = new ApiSystemRESTWrapper(
            new BinanceRESTMapper(
                credentials: Nidavellir::getSystemCredentials('binance')
            )
        );

        // Fetch symbol data from Binance using the Binance API job's logic
        $this->symbols = $api->getExchangeInformation()['symbols'] ?? [];

        // Check if symbols were fetched successfully
        if (empty($this->symbols)) {
            throw new ExchangeSymbolNotSyncedException(
                message: 'No symbols fetched from Binance',
                additionalData: ['exchange' => 'binance']
            );
        }

        // Filter symbols to include only those with USDT margin
        $this->symbols = $this->filterSymbolsWithUSDTMargin();

        // If no USDT margin symbols, throw an exception
        if (empty($this->symbols)) {
            throw new ExchangeSymbolNotSyncedException(
                message: 'No USDT margin symbols found from Binance data',
                additionalData: ['exchange' => 'binance']
            );
        }

        // Synchronize the filtered symbols with the local database
        $this->syncExchangeSymbols();
    }

    /**
     * Synchronizes the fetched and filtered Binance symbols with the
     * local database by creating or updating ExchangeSymbol records.
     */
    protected function syncExchangeSymbols()
    {
        // Retrieve the Binance exchange record from the database
        $exchange = ApiSystem::firstWhere('canonical', 'binance');

        // Throw an exception if the Binance exchange record is not found
        if (!$exchange) {
            throw new ExchangeSymbolNotSyncedException(
                message: 'Binance exchange record not found',
                additionalData: ['exchange' => 'binance']
            );
        }

        // Iterate over each symbol fetched from Binance
        foreach ($this->symbols as $data) {
            // Extract detailed data for the token
            $tokenData = $this->extractTokenData($data);
            $token = $tokenData['symbol'];

            // Find the symbol in the Symbol model
            $symbol = Symbol::firstWhere('token', $token);

            // If the symbol exists, create or update its ExchangeSymbol record
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

                // Create or update the ExchangeSymbol record
                ExchangeSymbol::updateOrCreate(
                    [
                        'symbol_id' => $symbolData['symbol_id'],
                        'exchange_id' => $exchange->id,
                    ],
                    $symbolData
                );
            }
        }
    }

    /**
     * Extracts relevant token data from the Binance API response item.
     * Includes details like precision and tick size.
     */
    private function extractTokenData($item)
    {
        // Extract the tick size from the item's filters
        $tickSize = collect($item['filters'])
            ->firstWhere('filterType', 'PRICE_FILTER')['tickSize'] ?? null;

        return [
            'symbol' => $item['baseAsset'], // Base asset of the symbol
            'precision_price' => $item['pricePrecision'], // Price precision
            'precision_quantity' => $item['quantityPrecision'], // Quantity precision
            'precision_quote' => $item['quotePrecision'], // Quote precision
            'tick_size' => $tickSize, // Tick size for the symbol
        ];
    }

    /**
     * Filters the fetched symbols to include only those with USDT as
     * their margin asset, ensuring only USDT-margined symbols are processed.
     */
    private function filterSymbolsWithUSDTMargin()
    {
        // Return only symbols where the margin asset is USDT
        return array_filter($this->symbols, function ($symbol) {
            return $symbol['marginAsset'] === 'USDT';
        });
    }
}
