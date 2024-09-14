<?php

namespace Nidavellir\Trading\Jobs\System\Taapi;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Nidavellir\Trading\Models\ApplicationLog;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\NidavellirException;

/**
 * UpsertSymbolIndicatorValuesJob fetches indicator values and
 * candle data from the Taapi.io API for symbols within a
 * specified rank. It updates indicators such as ATR, Bollinger
 * Bands, RSI, Stochastic, MACD, and the price amplitude
 * percentage for each symbol.
 */
class UpsertSymbolIndicatorValuesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 180;

    private $taapiEndpoint = 'https://api.taapi.io';

    private $taapiApiKey;

    private $constructLimit;

    private $maxRank;

    private $logBlock;

    public function __construct()
    {
        $this->taapiApiKey = config('nidavellir.system.api.credentials.taapi.api_key');
        $this->constructLimit = config('nidavellir.system.api.params.taapi.max_symbols_per_job');
        $this->maxRank = config('nidavellir.system.api.params.taapi.max_rank');
        $this->logBlock = Str::uuid();
    }

    public function handle()
    {
        ApplicationLog::withActionCanonical('UpsertSymbolIndicatorValuesJob.Start')
            ->withDescription('Starting job to update symbol indicators')
            ->withBlock($this->logBlock)
            ->saveLog();

        try {
            $symbols = $this->fetchOldestSymbols();

            ApplicationLog::withActionCanonical('UpsertSymbolIndicatorValuesJob.SymbolsFetched')
                ->withDescription('Fetched symbols to update')
                ->withReturnData(['symbols' => $symbols->pluck('token')->toArray()])
                ->withBlock($this->logBlock)
                ->saveLog();

            if ($symbols->isEmpty()) {
                ApplicationLog::withActionCanonical('UpsertSymbolIndicatorValuesJob.NoSymbols')
                    ->withDescription('No symbols found for update')
                    ->withBlock($this->logBlock)
                    ->saveLog();

                return;
            }

            foreach ($symbols as $symbol) {
                $this->fetchAndUpdateIndicators($symbol);
                $this->fetchAndUpdateCandle($symbol);
            }

            ApplicationLog::withActionCanonical('UpsertSymbolIndicatorValuesJob.End')
                ->withDescription('Successfully completed updating symbols')
                ->withBlock($this->logBlock)
                ->saveLog();
        } catch (\Throwable $e) {
            ApplicationLog::withActionCanonical('UpsertSymbolIndicatorValuesJob.Error')
                ->withDescription('Error occurred during symbol updates')
                ->withReturnData(['error' => $e->getMessage()])
                ->withSymbolId($symbols->first()->id ?? null)  // Use 'symbol_id'
                ->withBlock($this->logBlock)
                ->saveLog();

            throw new NidavellirException(
                originalException: $e,
                title: 'Error occurred while updating indicators or candles for symbol: '.($symbols->first()->token ?? 'Unknown Symbol'),
                loggable: $symbols->first()
            );
        }
    }

    private function fetchOldestSymbols()
    {
        return Symbol::where('rank', '<=', $this->maxRank)
            ->where('is_active', true)
            ->orderBy('updated_at', 'asc')
            ->limit($this->constructLimit)
            ->get();
    }

    private function fetchAndUpdateIndicators(Symbol $symbol)
    {
        $indicators = ['atr', 'bbands', 'rsi', 'stoch', 'macd'];

        foreach ($indicators as $indicator) {
            $url = "{$this->taapiEndpoint}/{$indicator}";
            $params = [
                'secret' => $this->taapiApiKey,
                'exchange' => 'binance',
                'symbol' => $symbol->token.'/USDT',
                'interval' => '1d',
            ];

            $response = Http::get($url, $params);

            if ($response->successful()) {
                $responseData = $response->json();
                $this->updateIndicators($symbol, $indicator, $responseData);

                ApplicationLog::withActionCanonical('UpsertSymbolIndicatorValuesJob.IndicatorUpdated')
                    ->withDescription("Updated $indicator for symbol: {$symbol->token}")
                    ->withReturnData(['symbol' => $symbol->token, 'indicator' => $indicator, 'response' => $responseData])
                    ->withSymbolId($symbol->id)  // Use 'symbol_id'
                    ->withBlock($this->logBlock)
                    ->saveLog();
            } else {
                $errorMessage = $response->body();

                ApplicationLog::withActionCanonical('UpsertSymbolIndicatorValuesJob.IndicatorFetchFailed')
                    ->withDescription("Failed to fetch $indicator for symbol: {$symbol->token}")
                    ->withReturnData(['symbol' => $symbol->token, 'error' => $errorMessage])
                    ->withSymbolId($symbol->id)  // Use 'symbol_id'
                    ->withBlock($this->logBlock)
                    ->saveLog();

                throw new NidavellirException(
                    title: 'Failed to fetch indicator from Taapi.io for symbol: '.$symbol->token,
                    additionalData: [
                        'symbol' => $symbol->token,
                        'indicator' => $indicator,
                        'api_error' => $errorMessage,
                    ],
                    loggable: $symbol
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

                ApplicationLog::withActionCanonical('UpsertSymbolIndicatorValuesJob.CandleUpdated')
                    ->withDescription("Updated candle data for symbol: {$symbol->token}")
                    ->withReturnData(['symbol' => $symbol->token, 'high' => $high, 'low' => $low])
                    ->withSymbolId($symbol->id)  // Use 'symbol_id'
                    ->withBlock($this->logBlock)
                    ->saveLog();
            }
        } else {
            ApplicationLog::withActionCanonical('UpsertSymbolIndicatorValuesJob.CandleFetchFailed')
                ->withDescription("Failed to fetch candle data for symbol: {$symbol->token}")
                ->withReturnData(['symbol' => $symbol->token, 'error' => $response->body()])
                ->withSymbolId($symbol->id)  // Use 'symbol_id'
                ->withBlock($this->logBlock)
                ->saveLog();

            throw new NidavellirException(
                title: 'Failed to fetch candle data from Taapi.io for symbol: '.$symbol->token,
                additionalData: ['symbol' => $symbol->token],
                loggable: $symbol
            );
        }
    }

    private function updateIndicators(Symbol $symbol, $indicator, $data)
    {
        if ($indicator === 'bbands') {
            $this->updateBollingerBands($symbol, $data);
        } elseif ($indicator === 'rsi') {
            $this->updateRSI($symbol, $data);
        } elseif ($indicator === 'stoch') {
            $this->updateStochastic($symbol, $data);
        } elseif ($indicator === 'macd') {
            $this->updateMACD($symbol, $data);
        } else {
            $value = $data['value'] ?? null;
            if ($value !== null) {
                $column = $this->getIndicatorColumnName($indicator);
                $symbol->update([$column => $value, 'updated_at' => Carbon::now()]);
            }
        }
    }

    private function updateRSI(Symbol $symbol, $data)
    {
        $rsi = $data['value'] ?? null;
        if ($rsi !== null) {
            $symbol->update([
                'indicator_rsi' => $rsi,
                'updated_at' => Carbon::now(),
            ]);
        }
    }

    private function updateBollingerBands(Symbol $symbol, $data)
    {
        $upperBand = $data['valueUpperBand'] ?? null;
        $middleBand = $data['valueMiddleBand'] ?? null;
        $lowerBand = $data['valueLowerBand'] ?? null;

        $symbol->update([
            'indicator_bbands_upper' => $upperBand,
            'indicator_bbands_middle' => $middleBand,
            'indicator_bbands_lower' => $lowerBand,
            'updated_at' => Carbon::now(),
        ]);
    }

    private function updateStochastic(Symbol $symbol, $data)
    {
        $valueK = $data['valueK'] ?? null;
        $valueD = $data['valueD'] ?? null;

        $symbol->update([
            'indicator_stochastic_k' => $valueK,
            'indicator_stochastic_d' => $valueD,
            'updated_at' => Carbon::now(),
        ]);
    }

    private function updateMACD(Symbol $symbol, $data)
    {
        $macd = $data['valueMACD'] ?? null;
        $macdSignal = $data['valueMACDSignal'] ?? null;
        $macdHist = $data['valueMACDHist'] ?? null;

        $symbol->update([
            'indicator_macd' => $macd,
            'indicator_macd_signal' => $macdSignal,
            'indicator_macd_hist' => $macdHist,
            'updated_at' => Carbon::now(),
        ]);
    }

    private function getIndicatorColumnName($indicator)
    {
        $mapping = [
            'atr' => 'indicator_atr',
            'bbands' => 'indicator_bollinger_bands',
            'rsi' => 'indicator_rsi',
            'stoch' => 'indicator_stochastic',
            'macd' => 'indicator_macd',
        ];

        return $mapping[$indicator] ?? null;
    }
}
