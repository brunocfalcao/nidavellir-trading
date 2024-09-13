<?php

namespace Nidavellir\Trading\Jobs\Positions;

use Illuminate\Support\Facades\Bus;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\PositionNotSyncedException;
use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Jobs\Orders\DispatchOrderJob;
use Nidavellir\Trading\Jobs\Tests\HardcodeMarketOrderJob;
use Nidavellir\Trading\Models\ApplicationLog;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\Nidavellir;
use Throwable;

/**
 * Class: DispatchPositionJob
 *
 * This class is responsible for managing the dispatching of
 * positions in a trading system. It validates mandatory
 * fields, selects eligible symbols, calculates the total
 * trade amount, and dispatches the orders linked to the
 * position.
 *
 * Important points:
 * - Handles errors through custom exceptions.
 * - Retrieves and sets the mark price, leverage, and symbol.
 * - Manages and sequences order dispatching through a chain.
 */
class DispatchPositionJob extends AbstractJob
{
    public Position $position;

    /**
     * Constructor for the job.
     *
     * @param  int  $positionId  The ID of the position to dispatch.
     */
    public function __construct(int $positionId)
    {
        $this->position = Position::find($positionId);
    }

    /**
     * Main handler for the job. It validates the position,
     * processes its settings, and dispatches the related orders.
     */
    public function handle()
    {
        try {
            /**
             * Log the start of the position dispatch process.
             */
            ApplicationLog::withActionCanonical('Position.Dispatch')
                ->withDescription('Job started')
                ->withLoggable($this->position)
                ->saveLog();

            $this->validateMandatoryFields();
            $this->computeTotalTradeAmount();
            $this->selectEligibleSymbol();
            $this->updatePositionSide();
            $this->setLeverage();
            $this->setLeverageOnToken();

            /**
             * Fetch and set the mark price if it has not been set.
             */
            if (blank($this->position->initial_mark_price)) {
                $this->fetchAndSetMarkPrice();
            }

            /**
             * Log key information about the position.
             */
            info_multiple(
                '=== POSITION ID '.$this->position->id,
                'Initial Mark Price: '.$this->position->initial_mark_price,
                'Leverage: '.$this->position->leverage,
                'Symbol: '.$this->position->exchangeSymbol->symbol->token,
                'Trader: '.$this->position->trader->name,
                'Total Trade Amount: '.$this->position->total_trade_amount,
                '===',
                ' '
            );

            /**
             * Dispatch the orders associated with the position.
             */
            $this->dispatchOrders($this->position);
        } catch (Throwable $e) {
            /**
             * Catch any errors and throw a custom exception for
             * synchronization failures.
             */
            throw new PositionNotSyncedException(
                $e,
                $this->position
            );
        }
    }

    /**
     * Validate that the position has all the necessary fields.
     */
    private function validateMandatoryFields()
    {
        if (blank($this->position->trader_id) ||
            blank($this->position->status) ||
            blank($this->position->trade_configuration)) {
            throw new PositionNotSyncedException(
                "Position ID {$this->position->id} missing mandatory fields",
                $this->position->id
            );
        }
    }

    /**
     * Calculate the total trade amount for the position.
     */
    private function computeTotalTradeAmount()
    {
        $configuration = $this->position->trade_configuration;

        if (blank($this->position->total_trade_amount)) {
            $availableBalance =
                $this->position->trader
                    ->withRESTApi()
                    ->withPosition($this->position)
                    ->getAccountBalance();

            $minimumTradeAmount = config('nidavellir.positions.minimum_trade_amount');

            if ($availableBalance == 0) {
                $this->updatePositionError($this->position, 'No USDT on Futures available balance.');

                return;
            }

            if ($availableBalance < $minimumTradeAmount) {
                $this->updatePositionError($this->position, "Less than {$minimumTradeAmount} USDT on Futures available balance (current: {$availableBalance}).");

                return;
            }

            $maxPercentageTradeAmount = $configuration['positions']['amount_percentage_per_trade'];
            $totalTradeAmount = round(floor($availableBalance * $maxPercentageTradeAmount / 100));

            $this->position->update(['total_trade_amount' => $totalTradeAmount]);
        }
    }

    /**
     * Select a random eligible symbol for the position.
     */
    private function selectEligibleSymbol()
    {
        if (blank($this->position->exchange_symbol_id)) {
            $eligibleSymbols = ExchangeSymbol::where('is_active', true)
                ->where('is_eligible', true)
                ->where('exchange_id', $this->position->trader->exchange_id)
                ->get();

            $excludedTokensFromConfig = config('nidavellir.symbols.excluded.tokens');
            $eligibleSymbols = $eligibleSymbols->reject(fn ($symbol) => in_array($symbol->symbol->token, $excludedTokensFromConfig));

            $otherTradeSymbolIds = $this->position->trader->positions->pluck('exchange_symbol_id')->toArray();
            $eligibleSymbols = $eligibleSymbols->reject(fn ($symbol) => in_array($symbol->id, $otherTradeSymbolIds));

            $exchangeSymbol = $eligibleSymbols->random();
            $this->position->update(['exchange_symbol_id' => $exchangeSymbol->id]);
        }
    }

    /**
     * Update the position's side (buy/sell).
     */
    private function updatePositionSide()
    {
        $this->position->update([
            'side' => $this->position->trade_configuration['positions']['current_side'],
        ]);
    }

    /**
     * Set the leverage for the position.
     */
    private function setLeverage()
    {
        $configuration = $this->position->trade_configuration;

        if (blank($this->position->leverage)) {
            $wrapper = new ExchangeRESTWrapper(
                new BinanceRESTMapper(credentials: Nidavellir::getSystemCredentials('binance'))
            );

            /**
             * Fetch leverage information based on the symbol.
             */
            $leverageData = $this->position
                                 ->exchangeSymbol
                                 ->api_notional_and_leverage_symbol_information;

            /**
             * Determine the maximum leverage available.
             */
            $possibleLeverage = Nidavellir::getMaximumLeverage(
                $leverageData,
                $this->position->exchangeSymbol->symbol->token.'USDT',
                $this->position->total_trade_amount
            );

            $leverage = min(config('nidavellir.positions.planned_leverage'), $possibleLeverage);
            $this->position->update(['leverage' => $leverage]);
        }
    }

    /**
     * Set the leverage for the token on the exchange.
     */
    private function setLeverageOnToken()
    {
        $this->position->trader
            ->withRESTApi()
            ->withPosition($this->position)
            ->withExchangeSymbol($this->position->exchangeSymbol)
            ->withOptions(['symbol' => $this->position->exchangeSymbol->symbol->token.'USDT', 'leverage' => $this->position->leverage])
            ->setDefaultLeverage();
    }

    /**
     * Fetch and set the initial mark price for the position.
     */
    private function fetchAndSetMarkPrice()
    {
        $markPrice = round($this->position->trader
            ->withRESTApi()
            ->withExchangeSymbol($this->position->exchangeSymbol)
            ->withPosition($this->position)
            ->withSymbol($this->position->exchangeSymbol->symbol->token.'USDT')
            ->getMarkPrice(), $this->position->exchangeSymbol->precision_price);

        $this->position->update(['initial_mark_price' => $markPrice]);
    }

    /**
     * Dispatch the orders linked to the position.
     */
    private function dispatchOrders()
    {
        $marketOrder = $this->position->orders->firstWhere('type', 'MARKET');
        $limitOrders = $this->position->orders->where('type', 'LIMIT');
        $profitOrder = $this->position->orders->firstWhere('type', 'PROFIT');

        $limitJobs = $limitOrders->map(
            fn ($limitOrder) => new DispatchOrderJob($limitOrder->id)
        )->toArray();

        /**
         * Dispatch orders in a specific sequence, starting
         * with limit orders, followed by market and profit orders.
         */
        Bus::chain([
            // Limit orders.
            Bus::batch($limitJobs),

            // Market order.
            //new DispatchOrderJob($marketOrder->id),

            // Hardcoding the market order to simulate the profit.
            //new HardcodeMarketOrderJob($this->position->id),

            // Profit order.
            //new DispatchOrderJob($profitOrder->id),

            //new ChangePositionToSyncedStatusJob($this->position->id),
        ])->dispatch();
    }

    /**
     * Update the position status to error if any issue occurs.
     *
     * @param  string  $message  The error message to log.
     */
    private function updatePositionError(string $message)
    {
        $this->position->update(['status' => 'error', 'comments' => $message]);
    }
}
