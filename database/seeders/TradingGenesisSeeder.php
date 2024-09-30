<?php

namespace Nidavellir\Trading\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Nidavellir\Trading\Jobs\ApiSystems\Binance\UpsertExchangeAvailableSymbolsJob;
use Nidavellir\Trading\Jobs\ApiSystems\Binance\UpsertNotionalAndLeverageJob;
use Nidavellir\Trading\Jobs\ApiSystems\CoinmarketCap\UpsertSymbolMetadataJob;
use Nidavellir\Trading\Jobs\ApiSystems\CoinmarketCap\UpsertSymbolsJob;
use Nidavellir\Trading\Models\ApiSystem;
use Nidavellir\Trading\Models\Trader;

class TradingGenesisSeeder extends Seeder
{
    public function run(): void
    {
        File::put(storage_path('logs/laravel.log'), ' ');

        $this->createApiSystems();
        $trader = $this->createTrader();
        $this->queueJobs();
    }

    private function createApiSystems(): void
    {
        ApiSystem::create([
            'name' => 'Binance',
            'canonical' => 'binance',
            'taapi_canonical' => 'binancefutures',
            'is_exchange' => true,
            'namespace_prefix_jobs' => "Nidavellir\Trading\Jobs\ApiSystems\Binance",
            'namespace_class_rest' => "Nidavellir\Trading\ApiSystems\Binance\BinanceRESTMapper",
            'namespace_class_websocket' => "Nidavellir\Trading\ApiSystems\Binance\BinanceWebsocketMapper",
            'futures_url_rest_prefix' => 'https://fapi.binance.com',
            'futures_url_websockets_prefix' => 'wss://fstream.binance.com',
        ]);

        ApiSystem::create([
            'name' => 'CoinmarketCap',
            'canonical' => 'coinmarketcap',
            'namespace_class_rest' => "Nidavellir\Trading\ApiSystems\CoinmarketCap\CoinmarketCapRESTMapper",
            'other_url_prefix' => 'https://pro-api.coinmarketcap.com/v1/cryptocurrency',
        ]);

        ApiSystem::create([
            'name' => 'Taapi',
            'canonical' => 'taapi',
            'namespace_class_rest' => "Nidavellir\Trading\ApiSystems\Taapi\TaapiRESTMapper",
            'namespace_prefix_jobs' => "Nidavellir\Trading\Jobs\ApiSystems\Taapi",
            'other_url_prefix' => 'https://api.taapi.io',
        ]);
    }

    private function createTrader(): Trader
    {
        return Trader::create([
            'name' => env('TRADER_NAME'),
            'email' => env('TRADER_EMAIL'),
            'password' => bcrypt(env('TRADER_PASSWORD')),
            'binance_api_key' => env('BINANCE_API_KEY'),
            'binance_secret_key' => env('BINANCE_SECRET_KEY'),
            'api_system_id' => ApiSystem::where('canonical', 'binance')->value('id'),
        ]);
    }

    private function queueJobs()
    {
        UpsertSymbolsJob::dispatchSync(20);
        UpsertSymbolMetadataJob::dispatchSync();

        UpsertExchangeAvailableSymbolsJob::dispatchSync();
        UpsertNotionalAndLeverageJob::dispatchSync();
    }
}
