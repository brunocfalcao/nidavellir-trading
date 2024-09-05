<?php

namespace Nidavellir\Trading\Jobs\Positions;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\Nidavellir;

class DispatchPositionJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $positionId;

    public function __construct(int $positionId)
    {
        $this->positionId = $positionId;
    }

    public function handle()
    {
        /**
         * Only runs for positions.status = new.
         *
         * Obtains the available portfolio amount, if needed.
         *
         * Selects an eligible token, if needed.
         *
         * Obtains token last mark price at the moment.
         *
         * Triggers the orders on the exchange, guarantees
         * all orders were successfully placed. Starts by
         * the limit-buy, then market, then computes the
         * total amount for the limit-sell order.
         *
         * If an order gives an error, cancels all other
         * orders and sets the positions.status = error.
         *
         * If no errors, then sets positions.status = active.
         *
         * If some data is already filled in, it will take
         * that data in account and not fetch the data
         * again from the exchange (in case we want to
         * test the position with specific test data).
         */
        $position = Position::find($this->positionId);

        /**
         * MANDATORY FIELDS
         * trader_id
         * status
         * trade_configuration
         */
        if (blank($position->trader_id) &&
           blank($position->status) &&
           blank($position->trade_configuration)) {
            throw new \Exception("Position ID {$position->id} without all mandatory fields");
        }

        $configuration = $position->trade_configuration;

        /**
         * Total trade amount computation.
         */
        if (blank($position->total_trade_amount)) {
            // Get trader available balance. Runs synchronously.

            $availableBalance =
            $position
                ->trader
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
            if (blank($usdtBalance)) {
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
         * Eligible symbol computation.
         */
        if (blank($position->exchange_symbol_id)) {
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
                    $position->trader->exchange_id
                )
                ->get();

            /**
             * Remove exchange symbols that are already being used
             * by the trader positions.
             */
            $trader = $position->trader;

            // Get the exchange_symbol_id from the trader's other positions
            $toRemoveIds = $trader->positions->pluck('exchange_symbol_id')->toArray();

            // Remove those exchange_symbol_ids from the $eligibleSymbols collection
            $eligibleSymbols = $eligibleSymbols->reject(function ($exchangeSymbol) use ($toRemoveIds) {
                return in_array($exchangeSymbol->id, $toRemoveIds);
            });

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
         * Update the position side, give the current
         * trade configuration.
         */
        $position->update(['side' => $position
            ->trade_configuration['positions']['current_side'],
        ]);

        /**
         * The leverage, it should try to current planned
         * leverage from the configuration and if not,
         * apply the maximum possible leverage for the
         * current trade amount.
         */
        if (blank($position->leverage)) {
            $wrapper = new ExchangeRESTWrapper(
                new BinanceRESTMapper(
                    credentials: Nidavellir::getSystemCredentials('binance')
                )
            );

            $leverageData =
            $wrapper
                ->withOptions(['symbol' => $position->exchangeSymbol->symbol->token.'USDT'])
                ->withExchangeSymbol($position->exchangeSymbol)
                ->withPosition($position)
                ->withTrader($trader)
                ->getLeverageBracket();

            $possibleLeverage = Nidavellir::getMaximumLeverage(
                $leverageData,
                $position->exchangeSymbol->symbol->token.'USDT',
                $position->total_trade_amount
            );

            /**
             * If the planned leverage (from config) is higher
             * than the possible leverage given the maximum
             * trade amount, then we need to reduce the
             * leverage to the possible leverage. This will
             * avoid errors with the leverage configuration
             */
            $leverage = min(config('nidavellir.positions.planned_leverage'), $possibleLeverage);

            // Update position leverage if it's null or if it's greater than the possible leverage.
            if ($position->leverage === null || $position->leverage > $possibleLeverage) {
                $position->update([
                    'leverage' => $leverage,
                ]);
            }
        }

        /**
         * Now that the position is configured, and so the
         * orders can start to be set via the current
         * trader api. The orders will be triggered via a
         * specific sequence. First the limit-buy, then the
         * market order, and finally the limit-sell order
         * (in case we are making LONGs).
         *
         * The orders are processed via the Bus::batch
         * and in case an order fails, we can cancel the
         * orders that were already placed.
         */

        foreach ($position->orders as $order) {
            dd($order);
        }
    }
}
