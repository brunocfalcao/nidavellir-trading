<?php

namespace Nidavellir\Trading\Jobs\ApiSystems\Taapi;

use Carbon\Carbon;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\ApiSystems\ApiSystemRESTWrapper;
use Nidavellir\Trading\ApiSystems\Taapi\TaapiRESTMapper;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Models\ApiSystem;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;

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
        $this->exchangeIds = $exchangeId ? [$exchangeId] : ApiSystem::where('is_exchange', true)->pluck('id')->toArray();
    }

    public function handle()
    {
        try {
            $this->api = new ApiSystemRESTWrapper(
                new TaapiRESTMapper(
                    credentials: Nidavellir::getSystemCredentials('taapi')
                )
            );

            foreach ($this->exchangeIds as $exchangeId) {
                if ($this->symbolToken) {
                    $this->processSymbol($this->symbolToken, $exchangeId);
                } else {
                    foreach (config('nidavellir.symbols.included') as $token) {
                        $this->processSymbol($token, $exchangeId);
                    }
                }
            }
            $this->jobPollerInstance->markAsComplete();
        } catch (\Throwable $e) {
            $this->jobPollerInstance->markAsError($e);
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

        $ma28Values = $this->fetchMa($exchangeCanonical, $symbolToken, 28, 2);
        $ma56Values = $this->fetchMa($exchangeCanonical, $symbolToken, 56, 2);

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
            $amplitudePercentage >= $this->amplitudeThreshold) {
            $exchangeSymbol->update(['side' => 'LONG']);
        } elseif ($ma28Values[0] >= $ma28Values[1] &&
            $ma56Values[0] >= $ma56Values[1] &&
            $ma56Values[1] >= $ma28Values[1] &&
            $ma56Values[1] > $ma28Values[1] &&
            $amplitudePercentage >= $this->amplitudeThreshold) {
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

        $responseData = $this->api->withOptions($params)->getMa();

        return $responseData['value'] ?? [];
    }
}
