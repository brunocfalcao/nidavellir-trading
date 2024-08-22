<?php

namespace Nidavellir\Trading\Jobs\Traders;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Models\Trader;

class UpdateCurrentPortfolioBalanceJob implements ShouldQueue
{
    use Batchable,
        Dispatchable,
        InteractsWithQueue,
        Queueable,
        SerializesModels;

    public Trader $trader;

    public function __construct(Trader $trader)
    {
        $this->trader = $trader;
    }

    public function handle()
    {
        /**
         * Fetch the trader's portfolio balance and update
         * it on the traders table for this trader.
         */
    }
}
