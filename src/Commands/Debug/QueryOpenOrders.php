<?php

namespace Nidavellir\Trading\Commands\Debug;

use Illuminate\Console\Command;
use Nidavellir\Trading\Models\Trader;

class QueryOpenOrders extends Command
{
    protected $signature = 'position:get-open-orders';

    protected $description = 'Queries open orders for a specific position id';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $trader = Trader::find(1);

        $orders = collect($trader
            ->withRESTApi()
            ->withOptions([
                'symbol' => 'ADAUSDT',
            ])
            ->getOpenOrders())->pluck('orderId');
    }
}
