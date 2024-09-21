<?php

namespace Nidavellir\Trading\Jobs\System\Taapi;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\IndicatorNotSyncedException;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Symbol;

/**
 * UpsertSymbolIndicatorValuesJob fetches indicator values and
 * candle data from the Taapi.io API for symbols within a
 * specified rank. It updates indicators such as EMA (7, 14, 28, 56)
 * and the price amplitude percentage for each symbol.
 */
class UpsertSymbolIndicatorValuesJob extends AbstractJob
{
    private $taapiEndpoint = 'https://api.taapi.io';

    private $taapiApiKey;

    private $constructLimit;

    private $maxRank;

    private $exchangeId;

    public function __construct($exchangeId)
    {
        $this->exchangeId = $exchangeId;
        $this->taapiApiKey = config('nidavellir.system.api.credentials.taapi.api_key');
        $this->constructLimit = config('nidavellir.system.api.params.taapi.max_symbols_per_job');
        $this->maxRank = config('nidavellir.system.api.params.taapi.max_rank');
        $this->logBlock = Str::uuid();
    }

    public function handle()
    {
        try {
            $symbols = $this->fetchEligibleSymbols();

            if ($symbols->isEmpty()) {
                return;
            }

            foreach ($symbols as $exchangeSymbol) {
                $symbol = $exchangeSymbol->symbol;
                $this->fetchAndUpdateIndicators($symbol);
                $this->fetchAndUpdateCandle($symbol);
            }
        } catch (\Throwable $e) {
            throw new TryCatchException(
                throwable: $e
            );
        }
    }

    private function fetchEligibleSymbols()
    {
        return ExchangeSymbol::with('symbol')
            ->where('exchange_id', $this->exchangeId)
            ->where('is_taapi_available', true)
            ->whereHas('symbol', function ($query) {
                $query->where('rank', '<=', $this->maxRank)
                    ->where('is_active', true);
            })
            ->orderBy(Symbol::select('indicator_last_synced_at')
                ->whereColumn('symbols.id', 'exchange_symbols.symbol_id'), 'asc')
            ->limit($this->constructLimit)
            ->get();
    }

    private function fetchAndUpdateIndicators(Symbol $symbol)
    {
        $indicators = [
            'ema_7' => ['endpoint' => 'ema', 'params' => ['optInTimePeriod' => 7]],
            'ema_14' => ['endpoint' => 'ema', 'params' => ['optInTimePeriod' => 14]],
            'ema_28' => ['endpoint' => 'ema', 'params' => ['optInTimePeriod' => 28]],
            'ema_56' => ['endpoint' => 'ema', 'params' => ['optInTimePeriod' => 56]],
        ];

        foreach ($indicators as $column => $indicator) {
            try {
                $url = "{$this->taapiEndpoint}/{$indicator['endpoint']}";
                $params = array_merge([
                    'secret' => $this->taapiApiKey,
                    'exchange' => 'binance',
                    'symbol' => $symbol->token.'/USDT',
                    'interval' => '1d',
                    'backtrack' => 1,
                ], $indicator['params']);

                $response = Http::get($url, $params);

                if ($response->successful()) {
                    $responseData = $response->json();

                    if (! isset($responseData['value'])) {
                        \Log::warning("Indicator value not available for $column for symbol: {$symbol->token}. Continuing to next indicator.");

                        continue;
                    }

                    $symbol->update([
                        $column => $responseData['value'],
                        'indicator_last_synced_at' => Carbon::now(),
                    ]);
                } else {
                    $errorMessage = $response->body();
                    throw new IndicatorNotSyncedException(
                        message: "Failed to fetch indicator '{$indicator['endpoint']}' from Taapi.io for symbol: {$symbol->token}",
                        additionalData: [
                            'symbol' => $symbol->token,
                            'indicator' => $column,
                            'api_error' => $errorMessage,
                        ]
                    );
                }
            } catch (IndicatorNotSyncedException $e) {
                \Log::warning("Indicator $column not available for symbol: {$symbol->token}. Error: {$e->getMessage()}");
            } catch (\Throwable $e) {
                throw new TryCatchException(
                    throwable: $e
                );
            }
        }
    }

    private function fetchAndUpdateCandle(Symbol $symbol)
    {
        $url = "{$this->taapiEndpoint}/candle";
        $params = [
            'secret' => $this->taapiApiKey,
            'exchange' => 'binance',
            'symbol' => $symbol->token.'/USDT',
            'interval' => '1d',
            'backtrack' => 1,
        ];

        $response = Http::get($url, $params);

        if ($response->successful()) {
            $data = $response->json();
            $high = $data['high'] ?? null;
            $low = $data['low'] ?? null;

            if ($high !== null && $low !== null && $low > 0) {
                $priceAmplitudePercentage = (($high - $low) / $low) * 100;

                $symbol->update([
                    'price_amplitude_highest' => $high,
                    'price_amplitude_lowest' => $low,
                    'price_amplitude_percentage' => $priceAmplitudePercentage,
                    'updated_at' => Carbon::now(),
                ]);
            }
        } else {
            throw new IndicatorNotSyncedException(
                message: 'Failed to fetch candle data from Taapi.io for symbol: '.$symbol->token,
                additionalData: [
                    'symbol' => $symbol->token,
                    'response' => $response->status()],
            );
        }
    }
}
