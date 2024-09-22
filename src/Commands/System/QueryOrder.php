<?php

namespace Nidavellir\Trading\Commands\System;

use Illuminate\Console\Command;
use Nidavellir\Trading\Models\Order;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Models\Trader;

class QueryOrder extends Command
{
    protected $signature = 'nidavellir:query {id}';

    protected $description = 'Queries an order id (Nidavellir) and returns the exchange order data';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $orderId = $this->argument('id');

        $order = Order::find($orderId);

        // Get the trader.
        $trader = $order->position->trader;

        // Get symbol.
        $symbol = $order->position->exchangeSymbol->symbol->token.'USDT';

        $orderInfo = $trader
            ->withRESTApi()
            ->withOrder($order)
            ->withOptions([
                'symbol' => $symbol,
                'orderId' => $order->order_exchange_system_id,
            ])
            ->getOrder();

        dd($orderInfo);
    }
}
