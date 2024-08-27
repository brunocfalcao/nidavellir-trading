<?php

namespace Nidavellir\Trading\Commands\System;

use Illuminate\Console\Command;
use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTMapper;
use Nidavellir\Trading\Models\Trader;

class CycleCommand extends Command
{
    protected $signature = 'nidavellir:cycle';

    protected $description = 'Generates and processes a new trading cycle (new orders, update, etc)';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        /**
         * The main action on this cycle is to actually update all
         * the token prices, and then call the event Cycle::class
         * for Nidavellir to analyse all trades. In case we then
         * need to open new trades, update limit sell orders and
         * so forth. All of this for each trader subscription
         * that is active on the system.
         */
        $exchangeRESTMapper = new ExchangeRESTMapper(
            new BinanceRESTMapper(Trader::find(1)),
        );

        $exchangeRESTMapper->placeSingleOrder(
            [
                'symbol' => 'SOLUSDT',
                'type' => 'LIMIT',
                'side' => 'BUY',
                'quantity' => 10,
                'price' => 130,
            ]
        );

        $exchangeRESTMapper->placeSingleOrder(
            [
                'symbol' => 'SOLUSDT',
                'type' => 'LIMIT',
                'side' => 'SELL',
                'quantity' => 10,
                'price' => 155,
            ]
        );
    }
}
