<?php

namespace Nidavellir\Trading\Jobs\System;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Abstracts\AbstractMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTMapper;
use Nidavellir\Trading\Models\Exchange;
use Nidavellir\Trading\Models\Symbol;

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
    }

    protected function syncExchangeSymbols($exchangeId, $symbols)
    {
        // Step 1: Mark existing entries for the given exchange as not synced
        $exchange = Exchange::find($exchangeId);

        if (! $exchange) {
            return; // Handle case where exchange does not exist
        }

        // Step 2 & 3: Sync or update the precision data for each symbol
        foreach ($symbols as $symbolToken => $precisions) {
            // Find the symbol by its token
            $symbol = Symbol::where('token', $symbolToken)->first();

            if ($symbol) {
                $attributes = [
                    'precision_price' => $precisions['precision']['price'],
                    'precision_quantity' => $precisions['precision']['quantity'],
                    'precision_quote' => $precisions['precision']['quote'],
                ];

                // Sync the pivot table with the new attributes
                $exchange->symbols()->syncWithoutDetaching([$symbol->id => $attributes]);
            }
        }
    }
}
