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

class UpsertExchangeAvailableSymbolsJob extends AbstractJob
{
    public ApiSystemRESTWrapper $wrapper;

    protected array $symbols;

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
            $mapper = $this->wrapper->mapper;
            $this->symbols = $mapper
                ->withLoggable(ApiSystem::find(1))
                ->getExchangeInformation()['symbols'];

            if (empty($this->symbols)) {
                throw new ExchangeSymbolNotSyncedException(
                    message: 'No symbols fetched from Binance',
                    additionalData: ['exchange' => 'binance']
                );
            }

            $this->symbols = $this->filterSymbolsWithUSDTMargin();

            if (empty($this->symbols)) {
                throw new ExchangeSymbolNotSyncedException(
                    message: 'No USDT margin symbols found from Binance data',
                    additionalData: ['exchange' => 'binance']
                );
            }

            $this->syncExchangeSymbols();
        } catch (\Throwable $e) {
            throw new TryCatchException(throwable: $e);
        }
    }

    protected function syncExchangeSymbols()
    {
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
                }
            } catch (\Throwable $e) {
                throw new TryCatchException(throwable: $e);
            }
        }
    }

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

    private function filterSymbolsWithUSDTMargin()
    {
        return array_filter($this->symbols, function ($symbol) {
            return $symbol['marginAsset'] === 'USDT';
        });
    }
}
