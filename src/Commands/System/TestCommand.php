<?php

namespace Nidavellir\Trading\Commands\System;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Jobs\Symbols\UpsertEligibleSymbolsJob;
use Nidavellir\Trading\Jobs\Symbols\UpsertSymbolMetadataJob;
use Nidavellir\Trading\Jobs\Symbols\UpsertSymbolsRankingJob;
use Nidavellir\Trading\Jobs\System\Binance\UpsertExchangeAvailableSymbolsJob;
use Nidavellir\Trading\Jobs\System\Binance\UpsertNotionalAndLeverageJob;
use Nidavellir\Trading\Jobs\System\Taapi\UpsertSymbolIndicatorValuesJob;
use Nidavellir\Trading\Jobs\System\UpsertFearGreedIndexJob;
use Nidavellir\Trading\Jobs\Tests\HardcodeMarketOrderJob;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Models\Trader;
use Nidavellir\Trading\Nidavellir;

class TestCommand extends Command
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
        //DB::table('positions')->truncate();
        //DB::table('orders')->truncate();
        DB::table('api_logs')->truncate();
        DB::table('application_logs')->truncate();
        DB::table('exceptions_log')->truncate();
        $this->testNewPosition();

        DispatchOrderJob::dispatchSync();

        //HardcodeMarketOrderJob::dispatchSync(Position::query()->first()->id);
        //UpsertFearGreedIndexJob::dispatchSync();
        //UpsertExchangeAvailableSymbolsJob::dispatchSync();
        //UpsertSymbolMetadataJob::dispatchSync();
        //UpsertNotionalAndLeverageJob::dispatchSync();
        //UpsertEligibleSymbolsJob::dispatchSync();
        //UpsertSymbolsRankingJob::dispatchSync();
        //UpsertSymbolIndicatorValuesJob::dispatchSync();
        //$this->getNotionalAndLeverageBrackets();
        //HardcodeMarketOrderJob::dispatchSync(Position::find(1)->id);
        //$this->queryOpenOrders();
        //$this->queryAllOrders();
        //$this->testTokenLeverage();
        //$this->getAccountBalance();
    }

    protected function getNotionalAndLeverageBrackets()
    {
        $mapper = (new ExchangeRESTWrapper(
            new BinanceRESTMapper(
                credentials: Nidavellir::getSystemCredentials('binance')
            )
        ))->mapper;

        dd($mapper->getLeverageBrackets());
    }

    private function queryOpenOrders()
    {
        /**
         * Obtain all open orders. If we are missing orders, we
         * will need to query those missing orders and
         * understand what happened to them. If they
         * are filled, or cancelled.
         */
        $trader = Trader::find(1);

        $openOrders = $trader->withRESTApi()
            ->getOpenOrders();

        dd($openOrders);
    }

    private function queryAllOrders()
    {
        $trader = Trader::find(1);

        $allOrders = collect($trader->withRESTApi()
            ->withOptions(['symbol' => 'SUNUSDT'])
            ->getAllOrders());

        dd(
            $allOrders->where('status', 'NEW')->pluck('origQty')->sum()
            //->where('side', 'BUY')
            //->sum('executedQty')
        );
    }

    private function getAccountBalance()
    {
        return Trader::find(1)
            ->withRESTApi()
            ->getAccountBalance();
    }

    private function getAccountInformation()
    {
        $accountInfo = Trader::find(1)
            ->withRESTApi()
            ->getAccountInformation();

        // Convert the result to pretty-printed JSON
        $json = json_encode($accountInfo, JSON_PRETTY_PRINT);

        // Clean the file before writing by replacing its contents
        Storage::put('public/account-information.json', $json);
    }

    private function getOpenOrders()
    {
        return Trader::find(1)
            ->withRESTApi()
            ->getOpenOrders();
    }

    /**
     * Tests a new position creation.
     */
    private function testNewPosition()
    {
        $position = Position::create([
            'trader_id' => Trader::find(1)->id,
            //'exchange_symbol_id' => 36,
            //'initial_mark_price' => 134.79,
            //'total_trade_amount' => 528.7
        ]);
    }
}
