<?php

namespace Nidavellir\Trading\Commands\Debug;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Nidavellir\Trading\ApiSystems\ApiSystemRESTWrapper;
use Nidavellir\Trading\ApiSystems\CoinmarketCap\CoinmarketCapRESTMapper;
use Nidavellir\Trading\ApiSystems\Taapi\TaapiRESTMapper;
use Nidavellir\Trading\Jobs\ApiSystems\CoinmarketCap\UpsertSymbolsJob;
use Nidavellir\Trading\Jobs\Symbols\UpsertSymbolTradeDirectionJob;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\Models\Trader;
use Nidavellir\Trading\Nidavellir;

class DebugCommand extends Command
{
    protected $signature = 'nidavellir:test';

    protected $description = 'Just a test command';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        File::put(storage_path('logs/laravel.log'), '');
        DB::table('positions')->truncate();
        DB::table('orders')->truncate();
        DB::table('api_requests_log')->truncate();
        DB::table('application_logs')->truncate();
        DB::table('exceptions_log')->truncate();

        UpsertSymbolTradeDirectionJob::dispatchSync();

        /*
        $wrapper = (new ApiSystemRESTWrapper(
            new CoinmarketCapRESTMapper(
                credentials: Nidavellir::getSystemCredentials('coinmarketcap')
            )
        ));

        dd($wrapper->withOptions(['limit' => 5])
            ->mapper
            ->getSymbols()['data']);
        */

        //UpsertSymbolsJob::dispatchSync(10);

        /*
        $wrapper = (new ApiSystemRESTWrapper(
            new TaapiRESTMapper(
                credentials: Nidavellir::getSystemCredentials('taapi')
            )
        ));

        dd($wrapper->withOptions(['exchange' => 'binancefutures'])
            ->getTaapiExchangeSymbols());
        */

        $this->info('All good. I hope.');
    }

    /**
     * Tests a new position creation.
     */
    private function testNewPosition()
    {
        $position = Position::create([
            'trader_id' => Trader::find(1)->id,
            'exchange_symbol_id' => 55,
            //'initial_mark_price' => 134.79,
            'total_trade_amount' => 75,
        ]);
    }
}
