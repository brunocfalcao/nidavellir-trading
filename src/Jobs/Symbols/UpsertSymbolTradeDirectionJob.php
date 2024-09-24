<?php

namespace Nidavellir\Trading\Jobs\Symbols;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Models\Symbol;

/**
 * UpsertSymbolTradeDirection fetches MA indicators for
 * a single symbol token defined in the job constructor
 * or processes all included symbols if no token is provided.
 */
class UpsertSymbolTradeDirectionJob extends AbstractJob
{
    private $taapiEndpoint = 'https://api.taapi.io/ma';

    private $taapiApiKey;

    private $exchange = 'binance';

    private $interval = '4h';

    private $symbolToken;

    private $amplitudeThreshold;

    public function __construct(string $symbolToken = null)
    {
        $this->symbolToken = $symbolToken;
        $this->taapiApiKey = config('nidavellir.system.api.credentials.taapi.api_key');
        $this->amplitudeThreshold = config('nidavellir.system.taapi.ma_min_amplitude_percentage');
        $this->interval = config('nidavellir.system.taapi.interval');
    }

    public function handle()
    {
        try {
            // If symbolToken is provided, process only that symbol.
            if ($this->symbolToken) {
                $this->processSymbol($this->symbolToken);
            } else {
                // Otherwise, process all symbols from the config.
                $includedSymbols = config('nidavellir.symbols.included');

                foreach ($includedSymbols as $token) {
                    $this->processSymbol($token);
                }
            }
        } catch (\Throwable $e) {
            throw new TryCatchException(throwable: $e);
        }
    }

    private function processSymbol(string $symbolToken)
    {
        // Fetch the symbol model using the provided token.
        $symbol = Symbol::where('token', $symbolToken)->first();

        if (!$symbol) {
            throw new \Exception("Symbol not found for token: {$symbolToken}");
        }

        // Fetch the latest MA values for both periods (28 and 56).
        $ma28Values = $this->fetchMa($symbolToken, 28, 2);
        $ma56Values = $this->fetchMa($symbolToken, 56, 2);

        // Ensure both sets of values are returned correctly.
        if (count($ma28Values) === 2 && count($ma56Values) === 2) {
            // Calculate amplitude percentage and absolute difference
            $amplitudeData = $this->calculateAmplitudePercentage($ma28Values[1], $ma56Values[1]);

            $symbol->update([
                'ma_28_2days_ago' => $ma28Values[0], // Oldest value
                'ma_28_yesterday' => $ma28Values[1], // Most recent value
                'ma_56_2days_ago' => $ma56Values[0], // Oldest value
                'ma_56_yesterday' => $ma56Values[1], // Most recent value
                'ma_amplitude_interval_percentage' => $amplitudeData['absolute_percentage'], // Calculated percentage
                'ma_amplitude_interval_absolute' => $amplitudeData['absolute_difference'],   // Calculated absolute difference
                'indicator_last_synced_at' => Carbon::now(),
            ]);

            // Refresh the model instance to get the latest changes
            $symbol->refresh();

            // Encapsulate the trade direction logic into a separate method
            $this->updateTradeDirection($symbol, $ma28Values, $ma56Values);
        }
    }

    private function updateTradeDirection(Symbol $symbol, array $ma28Values, array $ma56Values)
    {
        // Use the already calculated amplitude percentage from the symbol.
        $amplitudePercentage = $symbol->ma_amplitude_interval_percentage;

        // Verify upward trend and amplitude for LONG.
        if ($ma28Values[0] <= $ma28Values[1] && // MA 28 from 2 days ago is less than or equal to MA 28 from yesterday
            $ma56Values[0] <= $ma56Values[1] && // MA 56 from 2 days ago is less than or equal to MA 56 from yesterday
            $ma56Values[1] <= $ma28Values[1] && // MA 56 from yesterday is less than or equal to MA 28 from yesterday
            $ma56Values[1] < $ma28Values[1] &&  // MA 56 is lower than MA 28
            $amplitudePercentage >= $this->amplitudeThreshold // Check amplitude percentage
        ) {
            $symbol->update(['side' => 'LONG']); // Set to LONG
        }
        // Verify downward trend and amplitude for SHORT.
        elseif ($ma28Values[0] >= $ma28Values[1] && // MA 28 from 2 days ago is greater than or equal to MA 28 from yesterday
            $ma56Values[0] >= $ma56Values[1] && // MA 56 from 2 days ago is greater than or equal to MA 56 from yesterday
            $ma56Values[1] >= $ma28Values[1] && // MA 56 from yesterday is greater than or equal to MA 28 from yesterday
            $ma56Values[1] > $ma28Values[1] &&  // MA 56 is higher than MA 28
            $amplitudePercentage >= $this->amplitudeThreshold // Check amplitude percentage
        ) {
            $symbol->update(['side' => 'SHORT']); // Set to SHORT
        }
        // If neither condition is met, the trade direction remains unchanged.
    }

    /**
     * Calculate the absolute percentage amplitude between two values.
     */
    private function calculateAmplitudePercentage(float $ma28, float $ma56): array
    {
        $absoluteDifference = abs($ma28 - $ma56);
        $absolutePercentage = ($absoluteDifference / $ma28) * 100;

        return [
            'absolute_difference' => $absoluteDifference,
            'absolute_percentage' => $absolutePercentage,
        ];
    }

    private function fetchMa(string $symbolToken, int $period, int $results)
    {
        try {
            $params = [
                'secret' => $this->taapiApiKey,
                'exchange' => $this->exchange,
                'symbol' => "{$symbolToken}/USDT",
                'interval' => $this->interval,
                'optInTimePeriod' => $period,
                'results' => $results, // Fetch the latest two values
            ];

            $response = Http::get($this->taapiEndpoint, $params);

            // Ensure the response throws an exception if it's not a successful response
            $response->throw();

            $responseData = $response->json();

            // Ensure that 'value' exists and is an array
            if (isset($responseData['value']) && is_array($responseData['value'])) {
                return $responseData['value']; // Return the array with two values
            }

            return [];
        } catch (\Throwable $e) {
            throw new TryCatchException(
                message: "Error occurred while fetching MA for symbol: {$symbolToken} with period: {$period} on endpoint: {$this->taapiEndpoint}",
                throwable: $e,
                additionalData: [
                    'symbol' => $symbolToken,
                    'endpoint' => $this->taapiEndpoint,
                    'params' => $params,
                ]
            );
        }
    }
}
