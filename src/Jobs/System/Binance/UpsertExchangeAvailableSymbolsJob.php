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

class UpsertExchangeAvailableSymbolsJob implements ShouldQueue
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
        $mapper = $this->wrapper->mapper;

        $symbols = $mapper->getExchangeInformation();

        $this->syncExchangeSymbols($symbols);
    }

    protected function syncExchangeSymbols($symbols)
    {

        $exchange = $this->wrapper->mapper->exchange();

        // Step 2 & 3: Sync or update the precision data for each symbol
        foreach ($symbols as $symbolToken => $data) {
            $tokenData = $this->extractTokenData($data);

            $token = str_replace(['USDT', 'USDC'], '', $tokenData['symbol']);

            // Find the Exchange Symbol for this token.
            $exchangeSymbol = ExchangeSymbol::join('symbols', 'exchange_symbols.symbol_id', '=', 'symbols.id')
                ->where('symbols.token', $token)
                ->where('exchange_symbols.exchange_id', $exchange->id)
                ->first();

            $symbol = Symbol::firstWhere('token', $token);

            if ($symbol) {
                // Prepare the attributes for creation or update
                $symbolData = [
                    'symbol_id' => $symbol->id,
                    'exchange_id' => $exchange->id,
                    'precision_price' => $tokenData['precision_price'],
                    'precision_quantity' => $tokenData['precision_quantity'],
                    'precision_quote' => $tokenData['precision_quote'],
                    'tick_size' => $tokenData['tick_size'],
                    'api_data' => $data,
                ];

                // If ExchangeSymbol doesn't exist, create it
                if (! $exchangeSymbol) {
                    ExchangeSymbol::updateOrCreate(
                        ['symbol_id' => $symbolData['symbol_id'],
                            'exchange_id' => $exchange->id], // Conditions
                        $symbolData // Attributes to update or create
                    );
                } else {
                    // If it exists, update the necessary fields
                    $exchangeSymbol->update($symbolData);
                }
            }
        }
    }

    private function extractTokenData($item)
    {
        $tickSize = collect($item['filters'])->firstWhere('filterType', 'PRICE_FILTER')['tickSize'] ?? null;

        return [
            'symbol' => $item['symbol'],
            'precision_price' => $item['pricePrecision'],
            'precision_quantity' => $item['quantityPrecision'],
            'precision_quote' => $item['quotePrecision'],
            'tick_size' => $tickSize,
        ];
    }
}
