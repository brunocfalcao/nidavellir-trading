<?php

namespace Nidavellir\Trading\Jobs\Symbols;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\ApiSystems\ApiSystemRESTWrapper;
use Nidavellir\Trading\ApiSystems\Taapi\TaapiRESTMapper;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Models\ApiSystem;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;

/**
 * UpsertSymbolTradeDirection fetches MA indicators for
 * a single symbol token defined in the job constructor
 * or processes all included symbols if no token is provided.
 */
class UpsertSymbolTradeDirectionJob extends AbstractJob
{
    public $interval;

    public $symbolToken;

    public $amplitudeThreshold;

    public $exchangeIds;

    public $api;

    public function __construct(?string $symbolToken = null, ?string $exchangeId = null)
    {
        $this->symbolToken = $symbolToken;
        $this->amplitudeThreshold = config('nidavellir.system.taapi.ma_min_amplitude_percentage');
        $this->interval = config('nidavellir.system.taapi.interval');

        // Create a collection of the passed exchange id, or all exchange ids.
        $this->exchangeIds = $exchangeId ? [$exchangeId] :
        ApiSystem::where('is_exchange', true)->pluck('id')->toArray();
    }

    public function handle()
    {
        try {
            $this->api = new ApiSystemRESTWrapper(
                new TaapiRESTMapper(
                    credentials: Nidavellir::getSystemCredentials('taapi')
                )
            );

            // We retrieve the token(s) for all exchanges in the collection.
            foreach ($this->exchangeIds as $exchangeId) {
                if ($this->symbolToken) {
                    $this->processSymbol($this->symbolToken, $exchangeId);
                } else {
                    $includedSymbols = config('nidavellir.symbols.included');

                    foreach ($includedSymbols as $token) {
                        $this->processSymbol($token, $exchangeId);
                    }
                }
            }
        } catch (\Throwable $e) {
            throw new TryCatchException(throwable: $e);
        }
    }

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

        // Fetch the latest MA values for both periods (28 and 56).
        $ma28Values = $this->fetchMa($exchangeCanonical, $symbolToken, 28, 2);

        dd($ma28Values);

        return;
        $ma56Values = $this->fetchMa($symbolToken, 56, 2);

        if (count($ma28Values) === 2 && count($ma56Values) === 2) {
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

    public function updateTradeDirection(ExchangeSymbol $exchangeSymbol, array $ma28Values, array $ma56Values)
    {
        $amplitudePercentage = $exchangeSymbol->ma_amplitude_interval_percentage;

        if ($ma28Values[0] <= $ma28Values[1] &&
            $ma56Values[0] <= $ma56Values[1] &&
            $ma56Values[1] <= $ma28Values[1] &&
            $ma56Values[1] < $ma28Values[1] &&
            $amplitudePercentage >= $this->amplitudeThreshold
        ) {
            $exchangeSymbol->update(['side' => 'LONG']);
        } elseif ($ma28Values[0] >= $ma28Values[1] &&
            $ma56Values[0] >= $ma56Values[1] &&
            $ma56Values[1] >= $ma28Values[1] &&
            $ma56Values[1] > $ma28Values[1] &&
            $amplitudePercentage >= $this->amplitudeThreshold
        ) {
            $exchangeSymbol->update(['side' => 'SHORT']);
        }
    }

    public function calculateAmplitudePercentage(float $ma28, float $ma56): array
    {
        $absoluteDifference = abs($ma28 - $ma56);
        $absolutePercentage = ($absoluteDifference / $ma28) * 100;

        return [
            'absolute_difference' => $absoluteDifference,
            'absolute_percentage' => $absolutePercentage,
        ];
    }

    public function fetchMa($exchangeCanonical, string $symbolToken, int $period, int $results)
    {
        $params = [
            'exchange' => $exchangeCanonical,
            'symbol' => "{$symbolToken}/USDT",
            'interval' => $this->interval,
            'optInTimePeriod' => $period,
            'results' => $results,
        ];

        dd($params);

        $api->withOptions(['options' => $params])
            ->getMa();

        $response = Http::get($this->taapiEndpoint, $params);
        $response->throw();

        $responseData = $response->json();

        if (isset($responseData['value']) && is_array($responseData['value'])) {
            return $responseData['value'];
        }

        return [];
    }
}
