<?php

namespace Nidavellir\Trading\Jobs\System\Taapi;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Nidavellir\Trading\Exceptions\NidavellirException;
use Nidavellir\Trading\Models\Symbol;

/**
 * Class: UpsertSymbolIndicatorValuesJob
 *
 * This class fetches indicator values and candle data from the Taapi.io API
 * for symbols within a specified rank. It updates the relevant indicators
 * such as ATR, Bollinger Bands, RSI, Stochastic, MACD, and the price amplitude
 * percentage for each symbol.
 *
 * Important points:
 * - Fetches up to 3 symbols at a time as per the Pro plan limit.
 * - Handles multiple indicators and updates symbol data accordingly.
 */
class UpsertSymbolIndicatorValuesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $taapiEndpoint = 'https://api.taapi.io'; // Base endpoint for Taapi API

    private $taapiApiKey;

    private $constructLimit;

    private $maxRank;

    /**
     * Constructor to initialize API credentials and limits from configuration.
     */
    public function __construct()
    {
        $this->taapiApiKey = config('nidavellir.system.api.credentials.taapi.api_key');
        $this->constructLimit = config('nidavellir.system.api.params.taapi.max_symbols_per_job');
        $this->maxRank = config('nidavellir.system.api.params.taapi.max_rank');
    }

    /**
     * Main function to handle fetching and updating indicators and price data.
     */
    public function handle()
    {
        try {
            // Fetch symbols that are within the max rank, ordered by oldest updated_at
            $symbols = $this->fetchOldestSymbols();

            if ($symbols->isEmpty()) {
                return;
            }

            // Fetch and update data for each symbol
            foreach ($symbols as $symbol) {
                // Fetch and update each indicator for the symbol
                $this->fetchAndUpdateIndicators($symbol);

                // Fetch and update candle data for price amplitude calculation
                $this->fetchAndUpdateCandle($symbol);
            }
        } catch (\Throwable $e) {
            throw new NidavellirException(
                originalException: $e,
                title: 'Error occurred while updating indicators or candles for symbol: '.($symbols->first()->token ?? 'Unknown Symbol'),
                loggable: $symbols->first() // Eloquent model as loggable
            );
        }
    }

    /**
     * Fetches the oldest symbols that need to be updated, within the rank limit.
     *
     * @return Collection
     */
    private function fetchOldestSymbols()
    {
        return Symbol::where('rank', '<=', $this->maxRank)
            ->orderBy('updated_at', 'asc')
            ->limit($this->constructLimit)
            ->get();
    }

    /**
     * Fetches indicator data from Taapi.io for a symbol and updates the symbol's indicators.
     */
    private function fetchAndUpdateIndicators(Symbol $symbol)
    {
        $indicators = ['atr', 'bbands', 'rsi', 'stoch', 'macd'];

        foreach ($indicators as $indicator) {
            $url = "{$this->taapiEndpoint}/{$indicator}";

            $params = [
                'secret' => $this->taapiApiKey,
                'exchange' => 'binance',
                'symbol' => $symbol->token.'/USDT',
                'interval' => '1d', // Daily data
            ];

            $response = Http::get($url, $params);

            if ($response->successful()) {
                $responseData = $response->json();
                $this->updateIndicators($symbol, $indicator, $responseData);

                // Log the token and values received
                \Log::info("Token: {$symbol->token}, Indicator: {$indicator}, Values: ".json_encode($responseData));
            } else {
                throw new NidavellirException(
                    title: 'Failed to fetch indicator from Taapi.io for symbol: '.$symbol->token,
                    additionalData: ['symbol' => $symbol->token, 'indicator' => $indicator],
                    loggable: $symbol // Eloquent model as loggable
                );
            }
        }
    }

    /**
     * Fetches candle data for a symbol to calculate the price amplitude percentage.
     */
    private function fetchAndUpdateCandle(Symbol $symbol)
    {
        $url = "{$this->taapiEndpoint}/candle";
        $params = [
            'secret' => $this->taapiApiKey,
            'exchange' => 'binance',
            'symbol' => $symbol->token.'/USDT',
            'interval' => '1d', // Daily interval for price amplitude
        ];

        $response = Http::get($url, $params);

        if ($response->successful()) {
            $data = $response->json();

            $high = $data['high'] ?? null;
            $low = $data['low'] ?? null;

            if ($high !== null && $low !== null && $low > 0) {
                // Calculate amplitude percentage
                $priceAmplitudePercentage = (($high - $low) / $low) * 100;

                // Update the symbol with the calculated price amplitude percentage
                $symbol->update([
                    'price_amplitude' => $priceAmplitudePercentage,
                    'updated_at' => Carbon::now(),
                ]);

                \Log::info("Token: {$symbol->token}, Price Amplitude Percentage: {$priceAmplitudePercentage}%");
            }
        } else {
            throw new NidavellirException(
                title: 'Failed to fetch candle data from Taapi.io for symbol: '.$symbol->token,
                additionalData: ['symbol' => $symbol->token],
                loggable: $symbol // Eloquent model as loggable
            );
        }
    }

    /**
     * Updates the symbol with the fetched indicator data.
     *
     * @param  string  $indicator
     * @param  array  $data
     */
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

    /**
     * Returns the database column name for a given indicator.
     *
     * @param  string  $indicator
     * @return string|null
     */
    private function getIndicatorColumnName($indicator)
    {
        $mapping = [
            'atr' => 'indicator_atr',
            'bbands' => 'indicator_bollinger_bands', // Split into upper, middle, and lower
            'rsi' => 'indicator_rsi',
            'stoch' => 'indicator_stochastic',
            'macd' => 'indicator_macd',
        ];

        return $mapping[$indicator] ?? null;
    }
}
