<?php

namespace Nidavellir\Trading\Listeners\Positions;

use Illuminate\Support\Facades\Bus;
use Nidavellir\Trading\Abstracts\AbstractListener;
use Nidavellir\Trading\Events\Positions\PositionCreatedEvent;
use Nidavellir\Trading\Jobs\Traders\UpdateCurrentPortfolioBalanceJob;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Nidavellir;

class PositionCreatedListener extends AbstractListener
{
    public function handle(PositionCreatedEvent $event)
    {
        $position = $event->position;
        $trader = $position->trader;

        /**
         * Obtain the eligible symbols to open a trade.
         * The symbols that are marked as eligible are
         * the exchange_symbol.is_active=true.
         */
        $exchangeSymbols = ExchangeSymbol::where('is_active', true)
            ->where('exchange_id', $trader->exchange_id)
            ->get();

        /**
         * Remove exchange symbols that are already being used
         * by the trader positions.
         */

        // TODO.

        /**
         * Pick now a random eligible exchange symbol, normally
         * there are around 20 symbols available that are
         * selected everyday.
         */
        $exchangesymbol = $exchangeSymbols->random();

        /**
         * Compute the orders amounts, prices, ratios, and
         * type. The essence of the DCA. We call the
         * nidavellir trade configuration.
         */
        $configuration = Nidavellir::getTradeConfiguration();

        /**
         * With this trading configuration, and the exchange
         * symbol selected we can start the order creation
         * process.
         *
         * First step is to obtain the current remaining
         * portfolio amount, for the trade.
         *
         * With the remaining portfolio, we then need to
         * compute the trade percentage amount. The amount
         * will be splitted by all the orders, accordingly
         * to the amount scale logic.
         */
        Bus::chain([

            // Update current trader portfolio.
            new UpdateCurrentPortfolioBalanceJob($trader),

        ])->dispatch();
    }
}
