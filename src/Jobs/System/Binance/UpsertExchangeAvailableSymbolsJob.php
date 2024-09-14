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

    // Job timeout extended since we have +10.000 tokens to sync.
    public $timeout = 180;

    // API wrapper for interacting with Binance API.
    public ExchangeRESTWrapper $wrapper;

    // Array to store the fetched symbols.
    protected array $symbols;

    /**
     * Initializes the job by setting up the API wrapper with
     * Binance credentials.
     */
    public function __construct()
    {
        // Initialize the API wrapper with Binance credentials.
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
            // Get the Binance API mapper.
            $mapper = $this->wrapper->mapper;

            // Fetch symbols from Binance API.
            $this->symbols = $mapper->getExchangeInformation();

            if (empty($this->symbols)) {
                // Throw an exception if no symbols are fetched.
                throw new NidavellirException(
                    title: 'No symbols fetched from Binance',
                    additionalData: ['exchange' => 'binance']
                );
            }

            // Filter symbols to only include USDT margin symbols.
            $this->symbols = $this->filterSymbolsWithUSDTMargin();

            if (empty($this->symbols)) {
                // Throw an exception if no USDT margin symbols are found.
                throw new NidavellirException(
                    title: 'No USDT margin symbols found from Binance data',
                    additionalData: ['exchange' => 'binance']
                );
            }

            // Sync or update the exchange symbols in the database.
            $this->syncExchangeSymbols();
        } catch (Throwable $e) {
            // Handle any errors by raising a custom exception.
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
            // Throw an exception if the exchange record is not found.
            throw new NidavellirException(
                title: 'Binance exchange record not found',
                additionalData: ['exchange' => 'binance']
            );
        }

        // Iterate through each symbol and update or create records in the ExchangeSymbol model.
        foreach ($this->symbols as $symbolToken => $data) {
            try {
                // Extract token data from the fetched symbol information.
                $tokenData = $this->extractTokenData($data);

                // Get the base asset (token) from the symbol data.
                $token = $tokenData['symbol'];

                // Find the corresponding ExchangeSymbol for the token.
                $exchangeSymbol = ExchangeSymbol::with('symbol')
                    ->whereHas('symbol', function ($query) use ($token) {
                        $query->where('token', $token);
                    })
                    ->where('exchange_id', $exchange->id)
                    ->first();

                // Fetch the corresponding Symbol record.
                $symbol = Symbol::firstWhere('token', $token);

                if ($symbol) {
                    // Prepare the attributes for updating or creating the symbol data.
                    $symbolData = [
                        'symbol_id' => $symbol->id,
                        'exchange_id' => $exchange->id,
                        'precision_price' => $tokenData['precision_price'],
                        'precision_quantity' => $tokenData['precision_quantity'],
                        'precision_quote' => $tokenData['precision_quote'],
                        'tick_size' => $tokenData['tick_size'],
                        'api_symbol_information' => $data,
                    ];

                    // If ExchangeSymbol doesn't exist, create it.
                    if (! $exchangeSymbol) {
                        ExchangeSymbol::updateOrCreate(
                            [
                                'symbol_id' => $symbolData['symbol_id'],
                                'exchange_id' => $exchange->id,
                            ],
                            $symbolData
                        );
                    } else {
                        // If it exists, update the existing record.
                        $exchangeSymbol->update($symbolData);
                    }
                }
            } catch (Throwable $e) {
                // Throw an exception if there is an error while syncing a symbol.
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
        // Get tick size from the filters array.
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
