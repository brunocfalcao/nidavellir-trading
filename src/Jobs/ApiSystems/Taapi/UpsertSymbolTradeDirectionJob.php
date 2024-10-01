<?php

namespace Nidavellir\Trading\Jobs\ApiSystems\Taapi;

use Carbon\Carbon;
use Nidavellir\Trading\ApiSystems\ApiSystemRESTWrapper;
use Nidavellir\Trading\ApiSystems\Taapi\TaapiRESTMapper;
use Nidavellir\Trading\Jobs\ApiJobFoundations\TaapiApiJob;
use Nidavellir\Trading\Models\ApiSystem;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;

/**
 * UpsertSymbolTradeDirectionJob handles updating the trade
 * direction for each symbol by fetching moving averages (MA)
 * from Taapi.io. The job processes selected symbols based
 * on configured intervals, amplitude thresholds, and ensures
 * that trade direction data is correctly updated in the
 * `exchange_symbols` table.
 *
 * Important points:
 * - Uses Taapi.io API to fetch MA values.
 * - Determines whether the trade direction is LONG or SHORT.
 * - Updates amplitude data and trade direction for eligible symbols.
 */
class UpsertSymbolTradeDirectionJob extends TaapiApiJob
{
    // Interval used for fetching moving averages.
    public $interval;

    // The specific token for which the job is run.
    public $symbolToken;

    // Threshold percentage to determine if a trend is significant.
    public $amplitudeThreshold;

    // IDs of exchanges being processed.
    public $exchangeIds;

    // API instance used for fetching data.
    public $api;

    /**
     * Constructor initializes the job with optional token and exchange ID.
     */
    public function __construct(?string $symbolToken = null, ?string $exchangeId = null)
    {
        $this->symbolToken = $symbolToken;
        $this->amplitudeThreshold = config('nidavellir.system.taapi.ma_min_amplitude_percentage');
        $this->interval = config('nidavellir.system.taapi.interval');
        $this->exchangeIds = $exchangeId ? [$exchangeId] : ApiSystem::where('is_exchange', true)->pluck('id')->toArray();
    }

    // Implement the generalized compute method.
    protected function compute()
    {
        // Initialize the Taapi.io API Wrapper.
        $this->api = new ApiSystemRESTWrapper(
            new TaapiRESTMapper(
                credentials: Nidavellir::getSystemCredentials('taapi')
            )
        );

        // Process each exchange ID.
        foreach ($this->exchangeIds as $exchangeId) {
            if ($this->symbolToken) {
                $this->processSymbol($this->symbolToken, $exchangeId);
            } else {
                foreach (config('nidavellir.symbols.included') as $token) {
                    $this->processSymbol($token, $exchangeId);
                }
            }
        }
    }

    /**
     * Processes a specific symbol for a given exchange.
     */
    public function processSymbol(string $symbolToken, $exchangeId)
    {
        $symbol = Symbol::firstWhere('token', $symbolToken);
        $exchangeSymbol = ExchangeSymbol::where('exchange_id', $exchangeId)
            ->where('symbol_id', $symbol->id)
            ->first();

        if (! $exchangeSymbol) {
            throw new \Exception("ExchangeSymbol not found for token: {$symbolToken}");
        }

        $exchangeCanonical = ApiSystem::find($exchangeId)->taapi_canonical;

        // Fetch MA values for the past two days.
        $ma28Values = $this->fetchMa($exchangeCanonical, $symbolToken, 28, 2);
        $ma56Values = $this->fetchMa($exchangeCanonical, $symbolToken, 56, 2);

        // Ensure that valid MA values are available for both periods.
        if (count($ma28Values) === 2 && count($ma56Values) === 2) {
            // Calculate amplitude percentage and update the exchange symbol record.
            $amplitudeData = $this->calculateAmplitudePercentage($ma28Values[1], $ma56Values[1]);

            $exchangeSymbol->update([
                'ma_28_2days_ago' => $ma28Values[0],
                'ma_28_yesterday' => $ma28Values[1],
                'ma_56_2days_ago' => $ma56Values[0],
                'ma_56_yesterday' => $ma56Values[1],
                'ma_amplitude_interval_percentage' => $amplitudeData['absolute_percentage'],
                'ma_amplitude_interval_absolute' => $amplitudeData['absolute_difference'],
                'indicator_last_synced_at' => Carbon::now(),
            ]);

            $exchangeSymbol->refresh();
            $this->updateTradeDirection($exchangeSymbol, $ma28Values, $ma56Values);
        }
    }

    /**
     * Updates the trade direction (LONG/SHORT) based on MA values.
     */
    public function updateTradeDirection(ExchangeSymbol $exchangeSymbol, array $ma28Values, array $ma56Values)
    {
        $amplitudePercentage = $exchangeSymbol->ma_amplitude_interval_percentage;

        if ($ma28Values[0] <= $ma28Values[1] &&
            $ma56Values[0] <= $ma56Values[1] &&
            $ma56Values[1] <= $ma28Values[1] &&
            $ma56Values[1] < $ma28Values[1] &&
            $amplitudePercentage >= $this->amplitudeThreshold) {
            // Update to LONG trade direction.
            $exchangeSymbol->update(['side' => 'LONG']);
        } elseif ($ma28Values[0] >= $ma28Values[1] &&
            $ma56Values[0] >= $ma56Values[1] &&
            $ma56Values[1] >= $ma28Values[1] &&
            $ma56Values[1] > $ma28Values[1] &&
            $amplitudePercentage >= $this->amplitudeThreshold) {
            // Update to SHORT trade direction.
            $exchangeSymbol->update(['side' => 'SHORT']);
        }
    }

    /**
     * Calculates the amplitude percentage between two MA values.
     */
    public function calculateAmplitudePercentage(float $ma28, float $ma56): array
    {
        $absoluteDifference = abs($ma28 - $ma56);
        $absolutePercentage = ($absoluteDifference / $ma28) * 100;

        return [
            'absolute_difference' => $absoluteDifference,
            'absolute_percentage' => $absolutePercentage,
        ];
    }

    /**
     * Fetches moving average (MA) data from the Taapi.io API.
     */
    public function fetchMa($exchangeCanonical, string $symbolToken, int $period, int $results)
    {
        $params = [
            'exchange' => $exchangeCanonical,
            'symbol' => "{$symbolToken}/USDT",
            'interval' => $this->interval,
            'optInTimePeriod' => $period,
            'results' => $results,
        ];

        // Make API call to fetch MA values.
        $responseData = $this->api->withOptions($params)->getMa();

        return $responseData['value'] ?? [];
    }
}
