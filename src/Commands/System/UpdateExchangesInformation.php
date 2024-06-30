<?php

namespace Nidavellir\Trading\Commands\System;

use Illuminate\Console\Command;
use Nidavellir\Trading\Exchanges\Binance\BinanceMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTMapper;
use Nidavellir\Trading\Models\Trader;

class UpdateExchangesInformation extends Command
{
    protected $signature = 'trading:update-exchanges-information';

    protected $description = 'Updates all exchanges information (tokens, precision, etc)';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $exchange = new ExchangeRESTMapper(
            new BinanceMapper(Trader::find(1))
        );

        /*
        foreach ($exchange->->getExchangeInformation() as $symbol) {
            dd($symbol);
        }
        */

        //dd(array_keys($exchange->getExchangeInformation()));

        $this->info('All done.');
    }
}
