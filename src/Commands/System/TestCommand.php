<?php

namespace Nidavellir\Trading\Commands\System;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Models\Trader;

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
        /*
        $exchangeRESTMapper = new ExchangeRESTWrapper(
            new BinanceRESTMapper(Trader::find(1)),
        );

        dd($exchangeRESTMapper->getAccountBalance());
        */

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
