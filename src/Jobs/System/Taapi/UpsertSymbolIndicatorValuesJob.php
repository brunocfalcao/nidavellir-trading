<?php

namespace Nidavellir\Trading\Jobs\System\Taapi;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
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

    // Job timeout extended since we have +10.000 tokens to sync.
    public $timeout = 180;

    // Base endpoint for the Taapi API.
    private $taapiEndpoint = 'https://api.taapi.io';

    // Taapi API key from configuration.
    private $taapiApiKey;

    // Limit for the number of symbols to fetch in each job.
    private $constructLimit;

    // Maximum rank of symbols to be processed.
    private $maxRank;

    /**
     * Constructor to initialize API credentials and limits
     * from configuration.
     */
    public function __construct()
    {
        // Fetch the API key and limits from configuration.
        $this->taapiApiKey = config('nidavellir.system.api.credentials.taapi.api_key');
        $this->constructLimit = config('nidavellir.system.api.params.taapi.max_symbols_per_job');
        $this->maxRank = config('nidavellir.system.api.params.taapi.max_rank');
    }

    /**
     * Main function to handle fetching and updating indicators
     * and price data for symbols.
     */
    public function handle()
    {
        try {
            // Fetch symbols that are within the rank limit, ordered by the oldest update.
            $symbols = $this->fetchOldestSymbols();

            if ($symbols->isEmpty()) {
                return; // Exit if no symbols to update.
            }

            // Loop through each symbol and update its indicators and candle data.
            foreach ($symbols as $symbol) {
                $this->fetchAndUpdateIndicators($symbol); // Update indicator data.
                $this->fetchAndUpdateCandle($symbol); // Update candle data.
            }
        } catch (\Throwable $e) {
            // Handle exceptions by throwing a custom NidavellirException.
            throw new NidavellirException(
                originalException: $e,
                title: 'Error occurred while updating indicators or candles for symbol: '.($symbols->first()->token ?? 'Unknown Symbol'),
                loggable: $symbols->first()
            );
        }
    }

    /**
     * Fetches the oldest symbols that need to be updated,
     * within the rank limit.
     */
    private function fetchOldestSymbols()
    {
        return Symbol::where('rank', '<=', $this->maxRank)
                     ->where('is_active', true)
                     ->orderBy('updated_at', 'asc')
                     ->limit($this->constructLimit)
                     ->get();
    }

    /**
     * Fetches indicator data from Taapi.io for a symbol and
     * updates the symbol's indicators accordingly.
     */
    private function fetchAndUpdateIndicators(Symbol $symbol)
    {
        // List of indicators to fetch from Taapi.io.
        $indicators = ['atr', 'bbands', 'rsi', 'stoch', 'macd'];

        // Loop through each indicator and fetch the data.
        foreach ($indicators as $indicator) {
            $url = "{$this->taapiEndpoint}/{$indicator}";

            // Set the parameters for the API request.
            $params = [
                'secret' => $this->taapiApiKey,
                'exchange' => 'binance',
                'symbol' => $symbol->token.'/USDT',
                'interval' => '1d', // Daily data
            ];

            // Send the request to Taapi.io.
            $response = Http::get($url, $params);

            if ($response->successful()) {
                // Parse the response and update the indicators.
                $responseData = $response->json();
                $this->updateIndicators($symbol, $indicator, $responseData);
            } else {
                // Throw an exception if the API call fails.
                throw new NidavellirException(
                    title: 'Failed to fetch indicator from Taapi.io for symbol: '.$symbol->token,
                    additionalData: ['symbol' => $symbol->token, 'indicator' => $indicator],
                    loggable: $symbol
                );
            }
        }
    }

    /**
     * Fetches candle data for a symbol and updates the
     * price amplitude percentage.
     */
    private function fetchAndUpdateCandle(Symbol $symbol)
    {
        // API endpoint for fetching candle data.
        $url = "{$this->taapiEndpoint}/candle";

        // Parameters for fetching candle data.
        $params = [
            'secret' => $this->taapiApiKey,
            'exchange' => 'binance',
            'symbol' => $symbol->token.'/USDT',
            'interval' => '1d', // Daily interval
        ];

        // Send the request to Taapi.io.
        $response = Http::get($url, $params);

        if ($response->successful()) {
            // Parse the response data.
            $data = $response->json();
            $high = $data['high'] ?? null;
            $low = $data['low'] ?? null;

            // Calculate and update price amplitude if data is valid.
            if ($high !== null && $low !== null && $low > 0) {
                $priceAmplitudePercentage = (($high - $low) / $low) * 100;

                // Update the symbol with the calculated price amplitude.
                $symbol->update([
                    'price_amplitude' => $priceAmplitudePercentage,
                    'updated_at' => Carbon::now(),
                ]);
            }
        } else {
            // Throw an exception if the API call fails.
            throw new NidavellirException(
                title: 'Failed to fetch candle data from Taapi.io for symbol: '.$symbol->token,
                additionalData: ['symbol' => $symbol->token],
                loggable: $symbol
            );
        }
    }

    /**
     * Updates the symbol with the fetched indicator data.
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
            // Generic update for indicators like ATR.
            $value = $data['value'] ?? null;
            if ($value !== null) {
                $column = $this->getIndicatorColumnName($indicator);
                $symbol->update([$column => $value, 'updated_at' => Carbon::now()]);
            }
        }
    }

    // Updates the symbol with RSI data.
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

    // Updates the symbol with Bollinger Bands data.
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

    // Updates the symbol with Stochastic indicator data.
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

    // Updates the symbol with MACD indicator data.
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
     */
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
