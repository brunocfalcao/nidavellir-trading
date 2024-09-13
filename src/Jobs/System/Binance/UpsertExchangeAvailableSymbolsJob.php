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

/**
 * Class: UpsertExchangeAvailableSymbolsJob
 *
 * This class is responsible for fetching symbol information from Binance
 * and syncing that data with the local `ExchangeSymbol` model. It filters
 * symbols by USDT margin, updates symbol precision data, and ensures
 * that all relevant information is stored in the system.
 *
 * Important points:
 * - Fetches symbol data from the Binance API.
 * - Filters only symbols where 'marginAsset' is 'USDT'.
 * - Updates or creates ExchangeSymbol records with precision and tick size.
 */
class UpsertExchangeAvailableSymbolsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ExchangeRESTWrapper $wrapper;

    protected array $symbols;

    /**
     * Initializes the job by setting up the API wrapper
     * with Binance credentials.
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
     * Main function that handles fetching symbols from Binance,
     * filtering for USDT margin symbols, and syncing them with the database.
     */
    public function handle()
    {
        $mapper = $this->wrapper->mapper;

        // Get symbols from Binance API
        $this->symbols = $mapper->getExchangeInformation();

        /**
         * Filters out non-USDT margin symbols before syncing.
         */
        $this->symbols = $this->filterSymbolsWithUSDTMargin();

        // Sync or update the exchange symbols in the database
        $this->syncExchangeSymbols();
    }

    /**
     * Syncs the fetched symbols with the database by either updating
     * or creating new ExchangeSymbol records.
     */
    protected function syncExchangeSymbols()
    {
        $exchange = Exchange::firstWhere('canonical', 'binance');

        /**
         * Iterates through each symbol to update or create the relevant data
         * in the ExchangeSymbol model.
         */
        foreach ($this->symbols as $symbolToken => $data) {
            $tokenData = $this->extractTokenData($data);

            // Get the base asset (token) from the symbol data
            $token = $tokenData['symbol'];

            // Find the corresponding ExchangeSymbol for the token
            $exchangeSymbol = ExchangeSymbol::with('symbol')
                ->whereHas('symbol', function ($query) use ($token) {
                    $query->where('token', $token);
                })
                ->where('exchange_id', $exchange->id)
                ->first();

            // Fetch the corresponding Symbol record
            $symbol = Symbol::firstWhere('token', $token);

            if ($symbol) {
                // Prepare the attributes for updating or creating the symbol data
                $symbolData = [
                    'symbol_id' => $symbol->id,
                    'exchange_id' => $exchange->id,
                    'precision_price' => $tokenData['precision_price'],
                    'precision_quantity' => $tokenData['precision_quantity'],
                    'precision_quote' => $tokenData['precision_quote'],
                    'tick_size' => $tokenData['tick_size'],
                    'api_symbol_information' => $data,
                ];

                // If ExchangeSymbol doesn't exist, create it
                if (! $exchangeSymbol) {
                    ExchangeSymbol::updateOrCreate(
                        [
                            'symbol_id' => $symbolData['symbol_id'],
                            'exchange_id' => $exchange->id,
                        ],
                        $symbolData
                    );
                } else {
                    // If it exists, update the existing record
                    $exchangeSymbol->update($symbolData);
                }
            }
        }
    }

    /**
     * Extracts relevant token data (such as precision and tick size)
     * from the symbol information provided by the Binance API.
     */
    private function extractTokenData($item)
    {
        // Get tick size from the filters array
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
     * Filters the symbols fetched from Binance to only include
     * those with a 'marginAsset' of 'USDT'.
     */
    private function filterSymbolsWithUSDTMargin()
    {
        return array_filter($this->symbols, function ($symbol) {
            return $symbol['marginAsset'] === 'USDT';
        });
    }
}
