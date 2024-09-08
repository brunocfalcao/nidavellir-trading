<?php

namespace Nidavellir\Trading\Jobs\Positions;

use Illuminate\Support\Facades\Bus;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Jobs\Orders\DispatchOrderJob;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\Nidavellir;
use Throwable;

class _DispatchPositionJob extends AbstractJob
{
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
        $trader = $position->trader;

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
            $minimumTradeAmount = config('nidavellir.positions.minimum_trade_amount');

            // Does the trader has USDT's ?
            if ($availableBalance == 0) {
                $position->status = 'error';
                $position->comments = "Position not started since you don't have USDT on your Futures available balance.";
                $position->save();

                return;
            }

            // Does the trader has a minimum of USDT's for a trade?
            if ($availableBalance < $minimumTradeAmount) {
                $position->status = 'error';
                $position->comments = "Position not started since you have less than $minimumTradeAmount USDT on your Futures available balance (current: {$availableBalance}).";
                $position->save();

                return;
            }

            $maxPercentageTradeAmount = $configuration['positions']['amount_percentage_per_trade'];

            // Update total trade amount (USDT).
            $totalTradeAmount = round(floor($availableBalance * $maxPercentageTradeAmount / 100));

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
             * Remove exchange symbols that are part of the
             * excluded coins on the config file.
             */
            $excludedTokensFromConfig = config('nidavellir.symbols.excluded.tokens');

            $eligibleSymbols = $eligibleSymbols->reject(function ($exchangeSymbol) use ($excludedTokensFromConfig) {
                return in_array($exchangeSymbol->symbol->token, $excludedTokensFromConfig);
            });

            /**
             * Get the exchange_symbol_id's from the
             * trader's other positions.
             */
            $otherTradeSymbolIds = $trader->positions->pluck('exchange_symbol_id')->toArray();

            //
            /**
             * Remove those exchange_symbol_ids from
             * the $eligibleSymbols collection.
             */
            $eligibleSymbols = $eligibleSymbols->reject(function ($exchangeSymbol) use ($otherTradeSymbolIds) {
                return in_array($exchangeSymbol->id, $otherTradeSymbolIds);
            });

            /**
             * Pick a random eligible exchange symbol,
             * from the eligible ones.
             */
            $exchangeSymbol = $eligibleSymbols->random();

            $position->update([
                'exchange_symbol_id' => $exchangeSymbol->id,
            ]);
        } else {
            $exchangeSymbol = ExchangeSymbol::find($position->exchange_symbol_id);
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
         * Now that we know the leverage, we should set it
         * on the token. This will guarantee that the
         * leverage used will be the leveraged
         * computed.
         */
        $result = $position
            ->trader
            ->withRESTApi()
            ->withPosition($position)
            ->withExchangeSymbol($exchangeSymbol)
            ->withOptions([
                'symbol' => $exchangeSymbol->symbol->token.'USDT',
                'leverage' => $position->leverage])
            ->setDefaultLeverage();

        if (blank($position->initial_mark_price)) {
            /**
             * Obtain mark price just before triggering the orders
             * this will be important for the limit orders since
             * they will be priced accordingly to this mark
             * price.
             */
            $markPrice = $position
                ->trader
                ->withRESTApi()
                ->withExchangeSymbol($exchangeSymbol)
                ->withPosition($position)
            // TODO: Some exchanges might not like .USDT.
                ->withSymbol($exchangeSymbol->symbol->token.'USDT')
                ->getMarkPrice();

            // Update position with the mark price.
            $position->update([
                'initial_mark_price' => $markPrice,
            ]);
        }

        /**
         * Now that the position is configured, and so the
         * orders can start to be set via the current
         * trader api. The orders will be triggered via a
         * specific sequence. First the limit-buy, then the
         * market order, and finally the limit-sell order
         * (in case we are making LONGs).
         *
         * The orders are processed via the Bus::chain
         * and in case an order fails, we can cancel the
         * orders that were already placed. They are
         * processed in a specific order!
         *
         * Array of LIMIT (or PROFIT if shorting)
         * then MARKET
         * then PROFIT (or LIMIT if shorting).
         */
        $marketOrder = $position
            ->orders()
            ->firstWhere(
                'orders.type',
                'MARKET'
            );

        $limitOrders = $position
            ->orders()
            ->where(
                'orders.type',
                'LIMIT'
            )->get();

        $profitOrder = $position
            ->orders()
            ->firstWhere(
                'orders.type',
                'PROFIT'
            );

        $limitJobs = [];

        foreach ($limitOrders as $limitOrder) {
            $limitJobs[] = new DispatchOrderJob($limitOrder->id);
        }

        Bus::chain([

            // First the limit buy orders.
            Bus::batch([
                // Just a test for now, on 1 order.
                $limitJobs,
            ]),

            // Then the market order.
            new DispatchOrderJob($marketOrder->id),

            // Finally the take profit order.
            new DispatchOrderJob($profitOrder->id),
        ])
            ->catch(function (Throwable $e) {
                if ($e instanceof \App\Exceptions\OrderNotCreatedException) {
                    info('OrderNotCreatedException was thrown: '.$e->getMessage());
                } else {
                    info('There was an error: '.$e->getMessage());
                }
            })
            ->dispatch();
    }
}
