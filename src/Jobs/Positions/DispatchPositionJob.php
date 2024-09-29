<?php

namespace Nidavellir\Trading\Jobs\Positions;

use Nidavellir\Trading\Nidavellir;
use Illuminate\Support\Facades\Bus;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\JobPollerManager;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Jobs\Orders\DispatchOrderJob;
use Nidavellir\Trading\ApiSystems\ApiSystemRESTWrapper;
use Nidavellir\Trading\ApiSystems\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exceptions\DispatchPositionException;

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

    // Main handle method to process the job and dispatch the position's orders.
    public function handle()
    {
        try {
            // Perform mandatory field validation for the position.
            $this->validateMandatoryFields();

            // Compute the total trade amount if not already set.
            $this->computeTotalTradeAmount();

            // Select a symbol eligible for trading.
            $this->selectEligibleSymbol();

            // Update the side and profit ratio of the position.
            $this->updatePositionSideAndProfitRatio();

            // Set the margin type to 'CROSSED' for the position.
            $this->updateMarginTypeToCrossed();

            // Set the leverage for the position.
            $this->setLeverage();

            // Apply the leverage setting to the token.
            $this->setLeverageOnToken();

            // Fetch and set the initial mark price if not set.
            if (blank($this->position->initial_mark_price)) {
                $this->fetchAndSetMarkPrice();
            }

            // Log key information about the position.
            info_multiple(
                '=== POSITION ID ' . $this->position->id,
                'Side: ' . $this->position->side,
                'Initial Mark Price: ' . $this->position->initial_mark_price,
                'Leverage: ' . $this->position->leverage,
                'Symbol: ' . $this->position->exchangeSymbol->symbol->token,
                'Trader: ' . $this->position->trader->name,
                'Total Trade Amount: ' . $this->position->total_trade_amount,
                '===',
                ' '
            );

            // Dispatch the related orders for this position.
            $this->dispatchOrders($this->position);
            $this->jobPollerInstance->markAsComplete();
        } catch (\Throwable $e) {
            $this->jobPollerInstance->markAsError($e);
            // If an error occurs, mark the position's status as 'error'.
            if ($this->position) {
                $this->position->update(['status' => 'error']);
            }

            // Throw a TryCatchException with additional data.
            throw new TryCatchException(
                throwable: $e,
                additionalData: ['position_id' => $this->positionId]
            );
        }
    }

    // Sets the margin type for the position to 'CROSSED'.
    protected function updateMarginTypeToCrossed()
    {
        $exchangeSymbol = $this->position->exchangeSymbol;

        // Update the margin type using the trader's REST API.
        $this->position
            ->trader
            ->withRESTApi()
            ->withLoggable($this->position)
            ->withOptions([
                'symbol' => $exchangeSymbol->symbol->token . 'USDT',
                'margintype' => 'CROSSED'
            ])
            ->updateMarginType();
    }

    // Validates that all mandatory fields for the position are present.
    protected function validateMandatoryFields()
    {
        // Check if mandatory fields are blank and throw an exception if so.
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

        // Check if total trade amount is not set.
        if (blank($this->position->total_trade_amount)) {
            // Fetch the trader's available USDT balance using REST API.
            $availableBalance = $this->position->trader
                ->withRESTApi()
                ->withLoggable($this->position)
                ->getAccountBalance();

            $minimumTradeAmount = config('nidavellir.positions.minimum_trade_amount');

            // If balance is zero, mark the position as error.
            if ($availableBalance == 0) {
                $this->updatePositionError('No USDT on Futures available balance.');
                return;
            }

            // If balance is below the minimum, mark the position as error.
            if ($availableBalance < $minimumTradeAmount) {
                $this->updatePositionError("Less than {$minimumTradeAmount} USDT on Futures available balance (current: {$availableBalance}).");
                return;
            }

            // Calculate and set the total trade amount based on available balance.
            $maxPercentageTradeAmount = $configuration['positions']['amount_percentage_per_trade'];
            $totalTradeAmount = round(floor($availableBalance * $maxPercentageTradeAmount / 100));

            $this->position->update(['total_trade_amount' => $totalTradeAmount]);
        }
    }

    // Selects an eligible trading symbol for the position.
    protected function selectEligibleSymbol()
    {
        // Check if the exchange symbol is not already set.
        if (blank($this->position->exchange_symbol_id)) {
            $eligibleSymbols = ExchangeSymbol::where(
                'api_system_id',
                $this->position->trader->api_system_id
            )
                ->whereHas('symbol', function ($query) {
                    $query->whereIn(
                        'token',
                        config('nidavellir.symbols.included')
                    );
                })
                ->get();

            // Get all symbols currently being traded by the trader.
            $beingTradedSymbols = $this->position->trader->positions()
                ->whereNotIn('status', ['error', 'closed'])
                ->pluck('exchange_symbol_id')
                ->toArray();

            // Filter eligible symbols that are not currently being traded.
            $eligibleSymbols = $eligibleSymbols->reject(fn($symbol) => in_array($symbol->id, $beingTradedSymbols));
            $exchangeSymbol = $eligibleSymbols->random();

            // Update the position with the selected eligible symbol.
            $this->position->update(['exchange_symbol_id' => $exchangeSymbol->id]);
        }
    }

    // Updates the position with the correct side and profit ratio.
    protected function updatePositionSideAndProfitRatio()
    {
        $ratiosConfiguration = config('nidavellir.positions.current_order_ratio_group');
        $profitRatio = config("nidavellir.orders.{$ratiosConfiguration}.ratios.PROFIT")[0];

        // Update the position's side and initial profit percentage ratio.
        $this->position->update([
            'side' => $this->position->exchangeSymbol->symbol->side,
            'initial_profit_percentage_ratio' => $profitRatio,
        ]);
    }

    // Sets the leverage for the position based on configuration and limits.
    protected function setLeverage()
    {
        // Check if leverage is not already set.
        if (blank($this->position->leverage)) {
            $wrapper = new ApiSystemRESTWrapper(
                new BinanceRESTMapper(credentials: Nidavellir::getSystemCredentials('binance'))
            );

            $leverageData = $this->position->exchangeSymbol->api_notional_and_leverage_symbol_information;

            // Calculate the maximum leverage based on available data.
            $possibleLeverage = Nidavellir::getMaximumLeverage(
                $leverageData,
                $this->position->exchangeSymbol->symbol->token . 'USDT',
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
            ->withOptions(['symbol' => $this->position->exchangeSymbol->symbol->token . 'USDT', 'leverage' => $this->position->leverage])
            ->setDefaultLeverage();
    }

    // Fetches the current mark price for the position and updates it.
    protected function fetchAndSetMarkPrice()
    {
        $markPrice = round($this->position->trader
            ->withRESTApi()
            ->withLoggable($this->position)
            ->withOptions(['symbol' => $this->position->exchangeSymbol->symbol->token . 'USDT'])
            ->getMarkPrice(), $this->position->exchangeSymbol->precision_price);

        $this->position->update(['initial_mark_price' => $markPrice]);
    }

    // Dispatches the orders related to the position for execution.
    protected function dispatchOrders()
    {
        $marketOrder = $this->position->orders->firstWhere('type', 'MARKET');
        $limitOrders = $this->position->orders->where('type', 'LIMIT');
        $profitOrder = $this->position->orders->firstWhere('type', 'PROFIT');

        // Initialize the job poller manager to dispatch jobs.
        $jobPoller = new JobPollerManager;
        $jobPoller->newBlockUUID();

        // Prepare dispatch jobs for limit orders.
        foreach ($limitOrders as $limitOrder) {
            // Add each job using the $jobPoller method
            $jobPoller->withRelatable($limitOrder)->addJob(DispatchOrderJob::class, $limitOrder->id);
        }

        $jobPoller->withRelatable($marketOrder)->addJob(DispatchOrderJob::class, $marketOrder->id);
        $jobPoller->withRelatable($profitOrder)->addJob(DispatchOrderJob::class, $profitOrder->id);
        $jobPoller->withRelatable($this->position)->addJob(ValidatePositionJob::class, $this->position->id);

        // Update position status to 'syncing'.
        $this->position->update(['status' => 'syncing']);

        // Release jobs for later processing.
        $jobPoller->release();
    }

    // Updates the position status to 'error' with a specified message.
    protected function updatePositionError(string $message)
    {
        $this->position->update(['status' => 'error', 'comments' => $message]);
    }
}
