<?php

namespace Nidavellir\Trading\Jobs\System\Taapi;

use Illuminate\Support\Facades\Http;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Models\ApiSystem;
use Nidavellir\Trading\Models\ExchangeSymbol;

/**
 * UpsertTaapiAvailableSymbols updates the `is_taapi_available`
 * attribute for ExchangeSymbols for a given exchange. It ensures
 * daily re-evaluation, updating true/false as symbols may appear
 * or disappear on Taapi.io.
 */
class UpsertTaapiAvailableSymbols extends AbstractJob
{
    private $taapiEndpoint = 'https://api.taapi.io/exchange-symbols';

    private $taapiApiKey;

    private $exchangeId;

    public function __construct(int $exchangeId)
    {
        $this->exchangeId = $exchangeId;
        $this->taapiApiKey = config('nidavellir.system.api.credentials.taapi.api_key');
    }

    public function handle()
    {
        try {
            $exchange = ApiSystem::find($this->exchangeId);

            if (! $exchange) {
                return;
            }

            $exchangeSymbols = ExchangeSymbol::where('api_system_id', $this->exchangeId)
                ->get();

            if ($exchangeSymbols->isEmpty()) {
                return;
            }

            // Fetch symbols from Taapi.io using the canonical name
            $response = Http::get($this->taapiEndpoint, [
                'secret' => $this->taapiApiKey,
                'exchange' => $exchange->taapi_exchange_canonical,
            ]);

            if ($response->successful()) {
                $taapiSymbols = collect($response->json())
                    ->map(function ($symbol) {
                        return str_replace('/USDT', '', $symbol);
                    });

                // Set is_taapi_available to true or false based on Taapi data
                foreach ($exchangeSymbols as $exchangeSymbol) {
                    $isAvailable = $taapiSymbols->contains($exchangeSymbol->symbol->token);

                    // Update only if the api_system_id matches
                    ExchangeSymbol::where('id', $exchangeSymbol->id)
                        ->where('api_system_id', $this->exchangeId)
                        ->update(['is_taapi_available' => $isAvailable]);
                }
            } else {
                throw new TryCatchException(
                    message: "Failed to fetch symbols from Taapi.io for exchange ID: {$this->exchangeId}",
                    additionalData: [
                        'api_system_id' => $this->exchangeId,
                        'api_error' => $response->body(),
                    ]
                );
            }
        } catch (\Throwable $e) {
            throw new TryCatchException(
                throwable: $e
            );
        }
    }
}
