<?php

namespace Nidavellir\Trading\Commands\System;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Models\ExchangeSymbol;
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
        $this->testNewPosition();
    }

    private function testTokenLeverage()
    {
        $wrapper = new ExchangeRESTWrapper(
            new BinanceRESTMapper(
                credentials: Nidavellir::getSystemCredentials('binance')
            )
        );

        dd($wrapper
            ->withOptions(['symbol' => 'LTCUSDT'])
            ->getLeverageBracket());
    }

    /**
     * Tests a new position creation.
     */
    private function testNewPosition()
    {
        DB::table('positions')->truncate();
        DB::table('orders')->truncate();

        $position = Position::create([
            'trader_id' => Trader::find(1)->id,
            //'exchange_symbol_id' => 11,
            //'initial_mark_price' => 0.3271,
            //'total_trade_amount' => 528.7
        ]);

        return;

        // Open position with specific arguments.
        $symbol = Symbol::firstWhere('token', 'LTC');

        Trader::find(1)->positions()->create([
            'total_trade_amount' => 1000,
            'exchange_symbol_id' => ExchangeSymbol::firstWhere(
                'symbol_id',
                $symbol->id
            )->id,
        ]);

        $this->info('All good.');
    }
}
