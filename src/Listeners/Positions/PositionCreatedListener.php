<?php

namespace Nidavellir\Trading\Listeners\Positions;

use Illuminate\Support\Facades\Bus;
use Nidavellir\Trading\Abstracts\AbstractListener;
use Nidavellir\Trading\Events\Positions\PositionCreatedEvent;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Jobs\Orders\PlaceOrderJob;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Trader;
use Nidavellir\Trading\Nidavellir;

class PositionCreatedListener extends AbstractListener
{
    public function handle(PositionCreatedEvent $event)
    {
        $position = $event->position;
        $trader = $position->trader;

        /**
         * Lets grab the trading configuration. We will need
         * it along the orders creation workflow.
         */
        $configuration = Nidavellir::getTradeConfiguration();

        /**
         * In case the position has a nullable total trade
         * amount, we need to calculate the total trade amount
         * and insert into the position.
         */
        if ($position->total_trade_amount == null) {
            // Get trader available balance. Runs synchronously.

            $apiWrapper = new ExchangeRESTWrapper(
                $trader->getExchangeWrapperInUse()
            );

            $apiWrapper->withPosition($position);

            dd($apiWrapper->getAccountBalance());

            /**
             * Check if the trader's available amount is more than
             * the minimum accepted by the system. And if there are
             * USDT's in the future's balance.
             */
            $usdtBalance = $availableBalance['USDT'] ?? null;
            $minimumTradeAmount = config('nidavellir.positions.minimum_trade_amount');

            // Does the trader has USDT's ?
            if ($usdtBalance === null) {
                $position->status = 'error';
                $position->comments = "Position not started since you don't have USDT on your Futures available balance.";
                $position->save();

                return;
            }

            // Does the trader has a minimum of USDT's for a trade?
            if ($usdtBalance < $minimumTradeAmount) {
                $position->status = 'error';
                $position->comments = "Position not started since you have less than $minimumTradeAmount USDT on your Futures available balance (current: $usdtBalance).";
                $position->save();

                return;
            }

            $maxPercentageTradeAmount = $configuration['positions']['amount_percentage_per_trade'];

            // Update total trade amount (USDT).
            $position->update([
                'total_trade_amount' => round(floor($usdtBalance * $maxPercentageTradeAmount / 100)),
            ]);
        }

        /**
         * Obtain the eligible symbols to open a trade.
         * The symbols that are marked as eligible are
         * the exchange_symbol.is_active=true.
         */
        $exchangeSymbols = ExchangeSymbol::where('is_active', true)
            ->where('is_eligible', true)
            ->where('exchange_id', $trader->exchange_id)
            ->get();

        dd($exchangeSymbols->count());

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
        $exchangeSymbol = $exchangeSymbols->random();

        /**
         * Create the orders, based on the trading configuration
         * and on the market trend (bearish or bulish);
         */
        $ratios = $configuration['orders']['ratios'];

        $orders = [];
        foreach ($ratios as $ratio) {
            $orders[] = new PlaceOrderJob(
                $position,
                $exchangeSymbol,
                $ratio
            );
        }

        /**
         * The orders are placed as :
         * market order first
         * then the limit-"buy" group
         * then the limit-"sell"
         */
        Bus::chain([
            $orders,
        ])->dispatch();

        // Get the active exchange mapper for api interfacing.
        /*
        $apiWrapper = new ExchangeRESTWrapper(
            $trader->getExchangeWrapperInUse()
        );
        */

        /*
        $apiWrapper = new ExchangeRESTWrapper(
            new BinanceRESTMapper(Trader::find(1)),
        );
        */
    }
}
