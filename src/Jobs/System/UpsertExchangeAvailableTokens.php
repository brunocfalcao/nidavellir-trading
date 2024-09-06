<?php

namespace Nidavellir\Trading\Jobs\System;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Abstracts\AbstractMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
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
        $exchangeMapper = new ExchangeRESTWrapper($this->mapper);

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
        foreach ($symbols as $symbolToken => $data) {
            $tokenData = $this->extractTokenData($data);

            // Find the Exchange Symbol for this token.
            $symbol = Symbol::where('token', $symbolToken)->first();

            // TODO. Too tired.
        }
    }

    private function extractTokenData($item)
    {
        $tickSize = collect($item['filters'])->firstWhere('filterType', 'PRICE_FILTER')['tickSize'] ?? null;

        return [
            'symbol' => $item['symbol'],
            'precision_price' => $item['pricePrecision'],
            'precision_quantity' => $item['quantityPrecision'],
            'precision_quote' => $item['quotePrecision'],
            'tick_size' => $tickSize,
        ];
    }
}
