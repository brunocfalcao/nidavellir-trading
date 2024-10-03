<?php

namespace Nidavellir\Trading\Jobs\Positions;

use Illuminate\Support\Facades\Bus;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\ApiSystems\ApiSystemRESTWrapper;
use Nidavellir\Trading\ApiSystems\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exceptions\DispatchPositionException;
use Nidavellir\Trading\Jobs\Orders\DispatchOrderJob;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\Nidavellir;

/**
 * Class: DispatchPositionJob
 *
 * This job handles the entire process of dispatching a position
 * within the trading system. It performs validation, symbol selection,
 * leverage setting, and dispatching related orders.
 *
 * Important points:
 * - Validates the position’s configuration.
 * - Selects eligible trading symbols.
 * - Manages leverage, profit ratios, and trade amounts.
 * - Dispatches orders for execution.
 */
class DispatchPositionJob extends AbstractJob
{
    // The position being processed.
    public Position $position;

    // ID of the position being processed.
    public $positionId;

    public function __construct(int $positionId)
    {
        $this->positionId = $positionId;
        $this->position = Position::find($positionId);
    }

    // Main method that processes the job's logic inherited from AbstractJob.
    protected function compute()
    {
        // Attach the position model to jobQueueEntry for better tracking.
        $this->attachRelatedModel($this->position);

        // Perform all necessary steps for processing the position.
        $this->validateMandatoryFields();
        $this->computeTotalTradeAmount();
        $this->selectEligibleSymbol();
        $this->updatePositionSideAndProfitRatio();
        $this->updateMarginTypeToCrossed();
        $this->setLeverage();
        $this->setLeverageOnToken();

        // Fetch and set the initial mark price if not set.
        if (blank($this->position->initial_mark_price)) {
            $this->fetchAndSetMarkPrice();
        }

        // Dispatch the related orders for this position.
        $this->dispatchOrders($this->position);
    }

    // Sets the margin type for the position to 'CROSSED'.
    protected function updateMarginTypeToCrossed()
    {
        $exchangeSymbol = $this->position->exchangeSymbol;

        $this->position
            ->trader
            ->withRESTApi()
            ->withLoggable($this->position)
            ->withOptions([
                'symbol' => $exchangeSymbol->symbol->token.'USDT',
                'margintype' => 'CROSSED',
            ])
            ->updateMarginType();
    }

    // Validates that all mandatory fields for the position are present.
    protected function validateMandatoryFields()
    {
        if (blank($this->position->trader_id) ||
            blank($this->position->status) ||
            blank($this->position->trade_configuration)) {
            throw new DispatchPositionException(
                message: "Position ID {$this->position->id} missing mandatory fields",
                additionalData: ['position_id' => $this->position->id]
            );
        }
    }

    // Computes and sets the total trade amount for the position.
    protected function computeTotalTradeAmount()
    {
        $configuration = $this->position->trade_configuration;

        if (blank($this->position->total_trade_amount)) {
            $availableBalance = $this->position->trader
                ->withRESTApi()
                ->withLoggable($this->position)
                ->getAccountBalance();

            $minimumTradeAmount = config('nidavellir.positions.minimum_trade_amount');

            if ($availableBalance == 0) {
                $this->updatePositionError('No USDT on Futures available balance.');

                return;
            }

            if ($availableBalance < $minimumTradeAmount) {
                $this->updatePositionError("Less than {$minimumTradeAmount} USDT on Futures available balance (current: {$availableBalance}).");

                return;
            }

            $maxPercentageTradeAmount = $configuration['positions']['amount_percentage_per_trade'];
            $totalTradeAmount = round(floor($availableBalance * $maxPercentageTradeAmount / 100));
            $this->position->update(['total_trade_amount' => $totalTradeAmount]);
        }
    }

    // Selects an eligible trading symbol for the position.
    protected function selectEligibleSymbol()
    {
        if (blank($this->position->exchange_symbol_id)) {
            $eligibleSymbols = ExchangeSymbol::where(
                'exchange_id',
                $this->position->trader->api_system_id
            )
                ->whereHas('symbol', function ($query) {
                    $query->whereIn(
                        'token',
                        config('nidavellir.symbols.included')
                    );
                })
                ->get();

            $beingTradedSymbols = $this->position->trader->positions()
                ->whereNotIn('status', ['error', 'closed'])
                ->pluck('exchange_symbol_id')
                ->toArray();

            $eligibleSymbols = $eligibleSymbols->reject(fn ($symbol) => in_array($symbol->id, $beingTradedSymbols));
            $exchangeSymbol = $eligibleSymbols->random();

            $this->position->update(['exchange_symbol_id' => $exchangeSymbol->id]);
        }
    }

    // Updates the position with the correct side and profit ratio.
    protected function updatePositionSideAndProfitRatio()
    {
        $ratiosConfiguration = config('nidavellir.positions.current_order_ratio_group');
        $profitRatio = config("nidavellir.orders.{$ratiosConfiguration}.ratios.PROFIT")[0];

        $this->position->update([
            // If the position side is already saved, we don't change it.
            'side' => $this->position->side ?? $this->position->exchangeSymbol->side,
            'initial_profit_percentage_ratio' => $profitRatio,
        ]);
    }

    // Sets the leverage for the position based on configuration and limits.
    protected function setLeverage()
    {
        if (blank($this->position->leverage)) {
            $wrapper = new ApiSystemRESTWrapper(
                new BinanceRESTMapper(credentials: Nidavellir::getSystemCredentials('binance'))
            );

            $leverageData = $this->position->exchangeSymbol->api_notional_and_leverage_symbol_information;
            $possibleLeverage = Nidavellir::getMaximumLeverage(
                $leverageData,
                $this->position->exchangeSymbol->symbol->token.'USDT',
                $this->position->total_trade_amount
            );

            $leverage = min(config('nidavellir.positions.planned_leverage'), $possibleLeverage);
            $this->position->update(['leverage' => $leverage]);
        }
    }

    // Sets the leverage on the specific token associated with the position.
    protected function setLeverageOnToken()
    {
        $this->position->trader
            ->withRESTApi()
            ->withLoggable($this->position)
            ->withOptions(['symbol' => $this->position->exchangeSymbol->symbol->token.'USDT', 'leverage' => $this->position->leverage])
            ->setDefaultLeverage();
    }

    // Fetches the current mark price for the position and updates it.
    protected function fetchAndSetMarkPrice()
    {
        $markPrice = round($this->position->trader
            ->withRESTApi()
            ->withLoggable($this->position)
            ->withOptions(['symbol' => $this->position->exchangeSymbol->symbol->token.'USDT'])
            ->getMarkPrice(), $this->position->exchangeSymbol->precision_price);

        $this->position->update(['initial_mark_price' => $markPrice]);
    }

    // Dispatches the orders related to the position for execution.
    protected function dispatchOrders()
    {
        $marketOrder = $this->position->orders->firstWhere('type', 'MARKET');
        $limitOrders = $this->position->orders->where('type', 'LIMIT');
        $profitOrder = $this->position->orders->firstWhere('type', 'PROFIT');

        $profitOrderJobs = [];

        foreach ($limitOrders as $limitOrder) {
            $profitOrderJobs[] = new DispatchOrderJob($limitOrder->id);
        }

        $marketOrderJob = new DispatchOrderJob($marketOrder->id);
        $profitOrderJob = new DispatchOrderJob($profitOrder->id);

        $this->position->update(['status' => 'syncing']);

        Bus::chain([
            Bus::batch($profitOrderJobs),
            $marketOrderJob,
            $profitOrderJob,
            new ValidatePositionJob($this->position->id),
        ])->dispatch();
    }

    // Updates the position status to 'error' with a specified message.
    protected function updatePositionError(string $message)
    {
        $this->position->update(['status' => 'error', 'comments' => $message]);
    }
}
