<?php

namespace Nidavellir\Trading\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Nidavellir\Trading\Jobs\Symbols\UpsertSymbolMetadataJob;
use Nidavellir\Trading\Jobs\Symbols\UpsertSymbolsJob;
use Nidavellir\Trading\Jobs\Symbols\UpsertSymbolTradeDirectionJob;
use Nidavellir\Trading\Jobs\System\Binance\UpsertExchangeAvailableSymbolsJob;
use Nidavellir\Trading\Jobs\System\Binance\UpsertNotionalAndLeverageJob;
use Nidavellir\Trading\Jobs\System\Taapi\UpsertTaapiAvailableSymbols;
use Nidavellir\Trading\Models\System;
use Nidavellir\Trading\Models\Trader;

class TradingGenesisSeeder extends Seeder
{
    public function run(): void
    {
        File::put(storage_path('logs/laravel.log'), '');

        $exchange = new Exchange;
        $exchange->name = 'Binance';
        $exchange->canonical = 'binance';
        $exchange->full_qualified_class_name_rest = "Nidavellir\Trading\ApiSystems\Binance\BinanceRESTMapper";
        $exchange->full_qualified_class_name_websocket = "Nidavellir\Trading\ApiSystems\Binance\BinanceWebsocketMapper";
        $exchange->futures_url_rest_prefix = 'https://fapi.binance.com';
        $exchange->futures_url_websockets_prefix = 'wss://fstream.binance.com';
        $exchange->taapi_exchange_canonical = 'binancefutures';
        $exchange->save();

        $cmc = new Exchange;
        $cmc->name = 'CoinmarketCap';
        $cmc->canonical = 'coinmarketcap';
        $cmc->full_qualified_class_name_rest = "Nidavellir\Trading\ApiSystems\Coin\BinanceRESTMapper";
        $cmc->generic_url_prefix = 'https://pro-api.coinmarketcap.com/v1/cryptocurrency';
        $cmc->save();

        // Admin/standard trader person.
        $trader = new Trader;
        $trader->name = env('TRADER_NAME');
        $trader->email = env('TRADER_EMAIL');
        $trader->password = bcrypt(env('TRADER_PASSWORD'));
        $trader->binance_api_key = env('BINANCE_API_KEY');
        $trader->binance_secret_key = env('BINANCE_SECRET_KEY');
        $trader->api_system_id = $exchange->id;
        $trader->save();

        Bus::chain([
            // System jobs.
            new UpsertSymbolsJob(500),
            new UpsertSymbolMetadataJob,

            // Exchange-based jobs (Binance)
            new UpsertExchangeAvailableSymbolsJob,

            // Upsert notional and leverage data.
            new UpsertNotionalAndLeverageJob,

            // Sync Taapi.io symbols with exchange.
            new UpsertTaapiAvailableSymbols(1),

            // Get daily EMA's and update eligible symbols trade direction.
            new UpsertSymbolTradeDirectionJob,
        ])->dispatch();
    }
}
