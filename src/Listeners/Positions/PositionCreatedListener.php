<?php

namespace Nidavellir\Trading\Listeners\Positions;

use Nidavellir\Trading\Abstracts\AbstractListener;
use Nidavellir\Trading\Events\Positions\PositionCreatedEvent;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Order;
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

        $position->update([
            'trade_configuration' => $configuration,
        ]);

        /**
         * In case the position has a nullable total trade
         * amount, we need to calculate the total trade amount
         * and insert into the position.
         */
        if ($position->total_trade_amount == null) {
            // Get trader available balance. Runs synchronously.

            $availableBalance =
                $trader
                    ->withRESTApi()
                    ->withPosition($position)
                    ->getAccountBalance();

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
            $totalTradeAmount = round(floor($usdtBalance * $maxPercentageTradeAmount / 100));

            $position->update([
                'total_trade_amount' => $totalTradeAmount,
            ]);
        }

        /**
         * If we have an empty exchange symbol, then we
         * need to select an eligible one.
         */
        if ($position->exchange_symbol_id == null) {
            /**
             * Obtain the eligible symbols to open a trade.
             * The symbols that are marked as eligible are
             * the exchange_symbol.is_eligible = true.
             */
            $eligibleSymbols =
            ExchangeSymbol::where('is_active', true)
                ->where('is_eligible', true)
                ->where(
                    'exchange_id',
                    $trader->exchange_id
                )
                ->get();

            /**
             * Remove exchange symbols that are already being used
             * by the trader positions.
             */

            // TODO.

            /**
             * Pick a random eligible exchange symbol,
             * from the eligible ones.
             */
            $exchangeSymbol = $eligibleSymbols->random();

            $position->update([
                'exchange_symbol_id' => $exchangeSymbol->id,
            ]);
        }

        /**
         * If the position side (buy, sell) is null, we
         * need to get the current side from the
         * configuration.
         */
        if ($position->side == null) {
            $position->update([
                'side' => config(
                    'nidavellir.positions.current_side'
                ),
            ]);
        }

        /**
         * Create the orders, based on the trading
         * configuration and on the market trend
         * (bearish or bulish);
         */
        $configurationRatio = config('nidavellir.positions.current_ratio');

        $ratios = $configuration['orders'][$configurationRatio]['ratios'];

        foreach ($ratios as $ratio) {
            Order::create([
                'exchange_id' => $trader->exchange->id,
                'position_id' => $position->id,
                'price_percentage_ratio' => $ratio[0],
                'amount_divider' => $ratio[1],
            ]);
        }
    }
}
