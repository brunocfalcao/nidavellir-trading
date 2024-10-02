<?php

namespace Nidavellir\Trading\Commands\Debug;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Nidavellir\Trading\Models\Trader;

class CancelOpenOrders extends Command
{
    protected $signature = 'test:cancel-orders
                            {--token= : The token symbol to trade}';

    protected $description = 'Cancels all open orders from a token';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        File::put(storage_path('logs/laravel.log'), '');

        $trader = Trader::find(1);

        return $trader
            ->withRESTApi()
            ->withOptions([
                'symbol' => 'ADAUSDT',
            ])
            ->cancelOpenOrders();
    }
}
