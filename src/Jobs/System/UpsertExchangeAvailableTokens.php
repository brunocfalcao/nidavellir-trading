<?php

namespace Nidavellir\Trading\Jobs\System;

use Illuminate\Bus\Queueable;
use Nidavellir\Trading\Models\Symbol;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Models\Exchange;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Nidavellir\Trading\Abstracts\AbstractMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTMapper;

class UpsertExchangeAvailableTokens implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public AbstractMapper $mapper;

    public function __construct(AbstractMapper $mapper)
    {
        $this->mapper = $mapper;
    }

    public function handle()
    {
        $exchangeMapper = new ExchangeRESTMapper($this->mapper);

        $data = $exchangeMapper->getExchangeInformation();

        $this->syncExchangeSymbols($this->mapper->exchange()->id, $data);

        /**
         * Sometimes tokens get delisted. Although they get
         * delisted we cannot just delete them from the exchange
         * since we can have trading logs with them.
         *
         * Each time a sync happens we will mark 'was_synced'
         * to true, for each token. Then, the ones that weren't
         * synced (for that exchange), are delisted. We will need
         * to deactivate them so the traders can't use them for
         * that exchange.
         */
    }

    protected function syncExchangeSymbols($exchangeId, $symbols)
    {
        // Step 1: Mark existing entries for the given exchange as not synced
        $exchange = Exchange::find($exchangeId);

        if (!$exchange) {
            return; // Handle case where exchange does not exist
        }

        $exchange->symbols()->updateExistingPivot(null, ['was_synced' => false]);

        // Step 2 & 3: Sync or update the precision data for each symbol
        foreach ($symbols as $symbolToken => $precisions) {
            // Find the symbol by its token
            $symbol = Symbol::where('token', $symbolToken)->first();

            if ($symbol) {
                $attributes = [
                    'precision_price' => $precisions['precision']['price'],
                    'precision_quantity' => $precisions['precision']['quantity'],
                    'precision_quote' => $precisions['precision']['quote'],
                    'was_synced' => true,
                    'last_synced_at' => now(), // Update last_synced_at
                ];

                // Sync the pivot table with the new attributes
                $exchange->symbols()->syncWithoutDetaching([$symbol->id => $attributes]);
            }
        }

        // Step 4: Update the pivot column "is_active" to true where was_synced = true
        $exchange->symbols()->wherePivot('was_synced', true)->updateExistingPivot(null, ['is_active' => true]);

        // Step 5: Update the pivot column "is_active" to false where was_synced = false
        $exchange->symbols()->wherePivot('was_synced', false)->updateExistingPivot(null, ['is_active' => false]);

        // Step 6: Re-update was_synced to false on all pivot lines for the given exchange ID
        $exchange->symbols()->updateExistingPivot(null, ['was_synced' => false]);
    }
}
