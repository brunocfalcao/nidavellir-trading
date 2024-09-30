<?php

namespace Nidavellir\Trading\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Nidavellir\Trading\Models\Trader;
use Nidavellir\Trading\Models\ApiSystem;
use Nidavellir\Trading\Jobs\ApiSystems\CoinmarketCap\UpsertSymbolsJob;
use Nidavellir\Trading\Jobs\ApiSystems\Taapi\UpsertSymbolTradeDirectionJob;
use Nidavellir\Trading\Jobs\ApiSystems\Binance\UpsertNotionalAndLeverageJob;
use Nidavellir\Trading\Jobs\ApiSystems\CoinmarketCap\UpsertSymbolMetadataJob;
use Nidavellir\Trading\Jobs\ApiSystems\Binance\UpsertExchangeAvailableSymbolsJob;

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
        $testData = config('test');

        return Trader::create([
            'name' => $testData['trader_name'],
            'email' => $testData['trader_email'],
            'password' => bcrypt($testData['trader_password']),
            'binance_api_key' => $testData['binance_api_key'],
            'binance_secret_key' => $testData['binance_secret_key'],
            'api_system_id' => ApiSystem::where('canonical', 'binance')->value('id'),
        ]);
    }

    private function queueJobs()
    {
        Bus::chain([
            new UpsertSymbolsJob(500),
            new UpsertSymbolMetadataJob,
            new UpsertExchangeAvailableSymbolsJob,
            new UpsertNotionalAndLeverageJob,
            new UpsertSymbolTradeDirectionJob,
        ])->dispatch();
    }
}
