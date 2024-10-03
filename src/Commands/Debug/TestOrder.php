<?php

namespace Nidavellir\Trading\Commands\Debug;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Models\Trader;

class TestOrder extends Command
{
    protected $signature = 'test:order
                            {--amount= : The amount to be traded}
                            {--token= : The token symbol to trade}
                            {--side= : Order side, LONG, SHORT}
                            {--mark-price= : The initial mark price}';

    protected $description = 'Places a test order';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        /*
        $model = ExchangeSymbol::find(7);

        $lastMarkPrice = 0.15740000;

        $profitPositionIds =
            DB::table('positions')
            ->select('positions.id')
            ->distinct()
            ->join('orders', 'positions.id', '=', 'orders.position_id')
            ->where('positions.exchange_symbol_id', $model->id) // Positions tied to this ExchangeSymbol.
            ->where('positions.status', 'synced') // Only synced positions.
            ->where('orders.status', 'synced') // Only synced orders.
            ->where('orders.type', 'PROFIT') // Only profit orders.
            ->whereRaw("
            IF(
                positions.side = 'LONG',
                orders.entry_average_price <= ?,
                orders.entry_average_price >= ?
            )", [$lastMarkPrice, $lastMarkPrice]) // Apply condition based on position's side
            ->pluck('positions.id'); // Get only the position IDs

        dd($profitPositionIds);

        return;
        */
        File::put(storage_path('logs/laravel.log'), '');
        DB::table('positions')->truncate();
        DB::table('orders')->truncate();
        DB::table('api_requests_log')->truncate();
        DB::table('application_logs')->truncate();
        DB::table('exceptions_log')->truncate();
        DB::table('job_queue')->truncate();

        $amount = $this->option('amount') ?? 20;
        $token = $this->option('token') ?? collect(config('nidavellir.symbols.included'))->random();
        $markPrice = $this->option('mark-price');

        $symbol = Symbol::firstWhere('token', $token);

        $exchangeSymbol = ExchangeSymbol::where('symbol_id', $symbol->id)
            ->where('exchange_id', 1)
            ->first();

        // Compute side.
        $side = $this->option('side') ?? $exchangeSymbol->side;

        // Info message including mark price if provided
        $infoMessage = 'Placing '.
                       $side.
                       " order with amount: $amount and token: $token";
        if ($markPrice) {
            $infoMessage .= " at mark price: $markPrice";
        }

        $this->info($infoMessage);

        $positionData = [
            'trader_id' => Trader::find(1)->id,
            'side' => $side,
            'exchange_symbol_id' => $exchangeSymbol->id,
            'total_trade_amount' => $amount,
        ];

        // Include initial_mark_price if provided
        if ($markPrice) {
            $positionData['initial_mark_price'] = $markPrice;
        }

        $position = Position::create($positionData);
    }
}
