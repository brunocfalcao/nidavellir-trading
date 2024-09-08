<?php

namespace Nidavellir\Trading\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Bus;
use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Jobs\Symbols\UpsertEligibleSymbolsJob;
use Nidavellir\Trading\Jobs\Symbols\UpsertSymbolMetadata;
use Nidavellir\Trading\Jobs\Symbols\UpsertSymbolRankings;
use Nidavellir\Trading\Jobs\Symbols\UpsertSymbols;
use Nidavellir\Trading\Jobs\System\UpsertExchangeAvailableTokens;
use Nidavellir\Trading\Jobs\System\UpsertFearGreedIndexJob;
use Nidavellir\Trading\Models\Exchange;
use Nidavellir\Trading\Models\System;
use Nidavellir\Trading\Models\Trader;
use Nidavellir\Trading\Nidavellir;

class TradingGenesisSeeder extends Seeder
{
    public function run(): void
    {
        $exchange = new Exchange;
        $exchange->name = 'Binance';
        $exchange->canonical = 'binance';
        $exchange->full_qualified_class_name_rest = "Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper";
        $exchange->full_qualified_class_name_websocket = "Nidavellir\Trading\Exchanges\Binance\BinanceWebsocketMapper";
        $exchange->futures_url_rest_prefix = 'https://fapi.binance.com';
        $exchange->futures_url_websockets_prefix = 'wss://fstream.binance.com';
        $exchange->save();

        $cmc = new Exchange;
        $cmc->name = 'CoinmarketCap';
        $cmc->canonical = 'coinmarketcap';
        $cmc->full_qualified_class_name_rest = "Nidavellir\Trading\Exchanges\Coin\BinanceRESTMapper";
        $cmc->generic_url_prefix = 'https://pro-api.coinmarketcap.com/v1/cryptocurrency';
        $cmc->save();

        // Admin/standard trader person.
        $trader = new Trader;
        $trader->name = env('TRADER_NAME');
        $trader->email = env('TRADER_EMAIL');
        $trader->password = bcrypt(env('TRADER_PASSWORD'));
        $trader->binance_api_key = env('BINANCE_API_KEY');
        $trader->binance_secret_key = env('BINANCE_SECRET_KEY');
        $trader->exchange_id = $exchange->id;
        $trader->save();

        System::create([
            'fear_greed_index_threshold' => 85,
        ]);

        Bus::chain([
            // System jobs.
            new UpsertSymbols(200),
            new UpsertSymbolMetadata,
            new UpsertSymbolRankings,

            // Exchange-based jobs.
            new UpsertExchangeAvailableTokens(new BinanceRESTMapper(
                credentials: Nidavellir::getSystemCredentials('binance')
            )),

            // Disable non-elligible & non-ranked symbols.
            new UpsertEligibleSymbolsJob,

            // Update fear and greed index.
            new UpsertFearGreedIndexJob,
        ])->dispatch();
    }
}
