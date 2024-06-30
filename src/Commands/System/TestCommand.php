<?php

namespace Nidavellir\Trading\Commands\System;

use Illuminate\Console\Command;
use Nidavellir\Trading\Exchanges\Binance\BinanceMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTMapper;
use Nidavellir\Trading\Models\Trader;

class TestCommand extends Command
{
    protected $signature = 'trading:test';

    protected $description = 'Just a test command';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $exchangeMapper = new ExchangeRESTMapper(
            new BinanceMapper(Trader::find(1)),
        );

        dd($exchangeMapper->getExchangeInformation());
    }
}
