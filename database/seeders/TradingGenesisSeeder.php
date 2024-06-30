<?php

namespace Nidavellir\Trading\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Nidavellir\Trading\Exchanges\Binance\BinanceMapper;
use Nidavellir\Trading\Jobs\Symbols\UpsertSymbolRankings;
use Nidavellir\Trading\Jobs\Symbols\UpsertSymbols;
use Nidavellir\Trading\Jobs\Symbols\UpsertSymbolMetadata;
use Nidavellir\Trading\Jobs\System\UpsertExchangeAvailableTokens;
use Nidavellir\Trading\Models\Exchange;
use Nidavellir\Trading\Models\Trader;

class TradingGenesisSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $exchange = new Exchange();
        $exchange->name = 'Binance';
        $exchange->canonical = 'binance';
        $exchange->futures_url_prefix = 'https://fapi.binance.com';
        $exchange->save();

        // Admin/standard trader person.
        $trader = new Trader();
        $trader->name = env('TRADER_NAME');
        $trader->email = env('TRADER_EMAIL');
        $trader->password = bcrypt(env('TRADER_PASSWORD'));
        $trader->binance_api_key = env('BINANCE_API_KEY');
        $trader->binance_secret_key = env('BINANCE_SECRET_KEY');
        $trader->save();

        Bus::chain([
            // System jobs.
            new UpsertSymbols(200),
            new UpsertSymbolMetadata(),
            new UpsertSymbolRankings(),

            // Exchange-based jobs.
            new UpsertExchangeAvailableTokens(new BinanceMapper($trader)),
        ])->dispatch();

        /*
        // Import all symbols from Coinmarketcap.
        Artisan::call('trading:import-symbols', [
            'maxSymbols' => 500,
        ]);

        // Update image thumbnails.
        Artisan::call('trading:update-symbol-thumbnails');

        Artisan::call('trading:rank-symbols', []);

        // Import exchange information from Binance.
        Artisan::call('trading:update-exchanges-information');
        */
    }
}
