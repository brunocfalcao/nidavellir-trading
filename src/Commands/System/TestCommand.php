<?php

namespace Nidavellir\Trading\Commands\System;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
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
        //$this->queryOpenOrders();
        $this->testNewPosition();
        //$this->testTokenLeverage();
        //$this->getAccountBalance();
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

    private function testTokenLeverage()
    {
        $wrapper = new ExchangeRESTWrapper(
            new BinanceRESTMapper(
                credentials: Nidavellir::getSystemCredentials('binance')
            )
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
        DB::table('positions')->truncate();
        DB::table('orders')->truncate();
        DB::table('api_logs')->truncate();
        DB::table('application_logs')->truncate();
        DB::table('exceptions_log')->truncate();

        $position = Position::create([
            'trader_id' => Trader::find(1)->id,
            //'exchange_symbol_id' => 36,
            //'initial_mark_price' => 134.79,
            //'total_trade_amount' => 528.7
        ]);
    }
}
