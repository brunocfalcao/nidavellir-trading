<?php

namespace Nidavellir\Trading\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Nidavellir\Trading\JobPollerManager;
use Nidavellir\Trading\Jobs\ApiSystems\CoinmarketCap\UpsertSymbolMetadataJob;
use Nidavellir\Trading\Jobs\ApiSystems\CoinmarketCap\UpsertSymbolsJob;
use Nidavellir\Trading\Models\ApiSystem;
use Nidavellir\Trading\Models\Trader;

class TradingGenesisSeeder extends Seeder
{
    public function run(): void
    {
        File::put(storage_path('logs/laravel.log'), ' ');

        $apiSystem = new ApiSystem;
        $apiSystem->name = 'Binance';
        $apiSystem->canonical = 'binance';
        $apiSystem->is_exchange = true;
        $apiSystem->namespace_prefix_jobs = "Nidavellir\Trading\Jobs\ApiSystems\Binance";
        $apiSystem->namespace_class_rest = "Nidavellir\Trading\ApiSystems\Binance\BinanceRESTMapper";
        $apiSystem->namespace_class_websocket = "Nidavellir\Trading\ApiSystems\Binance\BinanceWebsocketMapper";
        $apiSystem->futures_url_rest_prefix = 'https://fapi.binance.com';
        $apiSystem->futures_url_websockets_prefix = 'wss://fstream.binance.com';
        $apiSystem->save();

        $cmc = new ApiSystem;
        $cmc->name = 'CoinmarketCap';
        $cmc->canonical = 'coinmarketcap';
        $cmc->namespace_class_rest = "Nidavellir\Trading\ApiSystems\CoinmarketCap\CoinmarketCapRESTMapper";
        $cmc->other_url_prefix = 'https://pro-api.coinmarketcap.com/v1/cryptocurrency';
        $cmc->save();

        $taapi = new ApiSystem;
        $taapi->name = 'Taapi';
        $taapi->canonical = 'taapi';
        $taapi->namespace_class_rest = "Nidavellir\Trading\ApiSystems\Taapi\TaapiRESTMapper";
        $taapi->namespace_prefix_jobs = "Nidavellir\Trading\Jobs\ApiSystems\Taapi";
        $taapi->other_url_prefix = 'https://api.taapi.io';
        $taapi->other_information = ['canonicals' => ['binance' => 'binancefutures']];
        $taapi->save();

        // Admin/standard trader person.
        $trader = new Trader;
        $trader->name = env('TRADER_NAME');
        $trader->email = env('TRADER_EMAIL');
        $trader->password = bcrypt(env('TRADER_PASSWORD'));
        $trader->binance_api_key = env('BINANCE_API_KEY');
        $trader->binance_secret_key = env('BINANCE_SECRET_KEY');
        $trader->api_system_id = $apiSystem->id;
        $trader->save();

        /**
         * Load Exchange jobs into the Bus Chain.
         * Each Exchange job has a specific naming convention
         * and it's prefixed by the Exchange namespace prefix
         * from the database exchange entry.
         */

        // Initialize the JobPollerManager
        $jobPoller = new JobPollerManager;

        // Start with a new block UUID
        $jobPoller->newBlockUUID();

        // Add the first chain of jobs under the same block UUID
        $jobPoller->addJob(UpsertSymbolsJob::class, 500)
            ->addJob(UpsertSymbolMetadataJob::class)
            ->add(); // This saves the chain to the job queue

        $jobPoller->release();

        return;

        // Iterate through each exchange and add exchange-specific job chains using the same block UUID
        foreach (ApiSystem::all()->where('is_exchange', true) as $exchange) {
            $nsPrefix = $exchange->namespace_prefix_jobs;

            // Add the exchange-specific jobs to the current block UUID
            $jobPoller->addJob($nsPrefix.'\\UpsertExchangeAvailableSymbolsJob')
                ->addJob($nsPrefix.'\\UpsertNotionalAndLeverageJob')
                ->add(); // Save the jobs for this exchange
        }

        // Finally, add the job that should be the last to run in the same block UUID
        $jobPoller->addJob(\Nidavellir\Job\UpsertTaapiAvailableSymbols::class)
            ->add(); // Save the final job

        $jobPoller->handle();
    }
}
