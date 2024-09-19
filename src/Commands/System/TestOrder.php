<?php

namespace Nidavellir\Trading\Commands\System;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Models\Trader;

class TestOrder extends Command
{
    protected $signature = 'nidavellir:order
                            {--amount= : The amount to be traded}
                            {--token= : The token symbol to trade}';

    protected $description = 'Places a test order';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        File::put(storage_path('logs/laravel.log'), '');
        DB::table('positions')->truncate();
        DB::table('orders')->truncate();
        DB::table('api_logs')->truncate();
        DB::table('application_logs')->truncate();
        DB::table('exceptions_log')->truncate();

        $amount = $this->option('amount') ?? 150;
        $token = $this->option('token') ?? 'TON';

        $symbol = Symbol::firstWhere('token', $token);

        $exchangeSymbol = ExchangeSymbol::where('symbol_id', $symbol->id)
            ->where('exchange_id', 1)
            ->first();

        $this->info("Placing order with amount: $amount and token: $token");

        $position = Position::create([
            'trader_id' => Trader::find(1)->id,
            'exchange_symbol_id' => $exchangeSymbol->id,
            //'initial_mark_price' => 134.79,
            'total_trade_amount' => $amount,
        ]);
    }
}
