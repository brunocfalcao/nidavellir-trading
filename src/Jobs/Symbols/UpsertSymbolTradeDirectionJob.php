<?php

namespace Nidavellir\Trading\Jobs\Symbols;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Models\Symbol;

/**
 * UpsertSymbolTradeDirection fetches EMA indicators for
 * symbols defined in the nidavellir config and updates
 * the trade direction.
 */
class UpsertSymbolTradeDirectionJob extends AbstractJob
{
    private $taapiEndpoint = 'https://api.taapi.io/ma';

    private $taapiApiKey;

    private $exchange = 'binance';

    private $interval = '1d';

    private $includedSymbols;

    public function __construct()
    {
        $this->taapiApiKey = config('nidavellir.system.api.credentials.taapi.api_key');
        $this->includedSymbols = config('nidavellir.symbols.included');
    }

    public function handle()
    {
        try {
            $symbols = Symbol::whereIn('token', $this->includedSymbols)->get();

            foreach ($symbols as $symbol) {
                // Fetch the latest EMA values for both periods (28 and 56)
                $ema28Values = $this->fetchEma($symbol->token, 28, 2);
                $ema56Values = $this->fetchEma($symbol->token, 56, 2);

                // Ensure both sets of values are returned correctly
                if (count($ema28Values) === 2 && count($ema56Values) === 2) {
                    $symbol->update([
                        'ema_28_2days_ago' => $ema28Values[0], // Oldest value
                        'ema_28_yesterday' => $ema28Values[1], // Most recent value
                        'ema_56_2days_ago' => $ema56Values[0], // Oldest value
                        'ema_56_yesterday' => $ema56Values[1], // Most recent value
                        'indicator_last_synced_at' => Carbon::now(),
                    ]);

                    // Determine if the trade direction should be updated to "BUY"
                    if ($ema28Values[0] < $ema28Values[1] && // EMA 28 from 2 days ago is less than EMA 28 from yesterday
                        $ema56Values[0] < $ema56Values[1] && // EMA 56 from 2 days ago is less than EMA 56 from yesterday
                        $ema56Values[1] <= $ema28Values[1] && // EMA 56 from yesterday is less than or equal to EMA 28 from yesterday
                        $ema56Values[1] < $ema28Values[1]     // EMA 56 is lower than EMA 28
                    ) {
                        $symbol->update(['side' => 'BUY']); // Set to BUY
                    } elseif ($ema28Values[0] > $ema28Values[1] && // EMA 28 from 2 days ago is greater than EMA 28 from yesterday
                        $ema56Values[0] > $ema56Values[1] && // EMA 56 from 2 days ago is greater than EMA 56 from yesterday
                        $ema56Values[1] >= $ema28Values[1] && // EMA 56 from yesterday is greater than or equal to EMA 28 from yesterday
                        $ema56Values[1] > $ema28Values[1]     // EMA 56 is higher than EMA 28
                    ) {
                        $symbol->update(['side' => 'SELL']); // Set to SELL
                    }
                    // If neither condition is met, the trade direction remains unchanged.
                }
            }
        } catch (\Throwable $e) {
            throw new TryCatchException(throwable: $e);
        }
    }

    private function fetchEma(string $symbolToken, int $period, int $results)
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
                message: "Error occurred while fetching EMA for symbol: {$symbolToken} with period: {$period} on endpoint: {$this->taapiEndpoint}",
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
