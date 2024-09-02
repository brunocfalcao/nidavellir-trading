<?php

namespace Nidavellir\Trading\Commands\System;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Models\ExchangeSymbol;
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
        $wrapper = new ExchangeRESTWrapper(
            new BinanceRESTMapper(
                credentials: Nidavellir::getSystemCredentials('binance')
            )
        );

        dd($wrapper
            ->withOptions(['symbol' => 'DASHUSDT'])
            ->getLeverageBracket());

    }

    /**
     * Tests a new position creation.
     */
    private function testNewPosition()
    {
        DB::table('positions')->truncate();
        DB::table('orders')->truncate();

        // Open position with specific arguments.
        $symbol = Symbol::firstWhere('token', 'SOL');

        Trader::find(1)->positions()->create([
            'total_trade_amount' => 100,
            'exchange_symbol_id' => ExchangeSymbol::firstWhere(
                'symbol_id',
                $symbol->id
            )->id,
        ]);

        $this->info('All good.');
    }
}
