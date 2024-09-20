<?php

namespace Nidavellir\Trading\Jobs\Positions;

use Illuminate\Support\Facades\Bus;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\DispatchPositionException;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Jobs\Orders\DispatchOrderJob;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\Nidavellir;

/**
 * DispatchPositionJob manages the dispatching of positions
 * in a trading system. It validates mandatory fields, selects
 * eligible symbols, calculates the total trade amount, and
 * dispatches the orders linked to the position.
 */
class DispatchPositionJob extends AbstractJob
{
    // Holds the position being dispatched.
    public Position $position;

    // Holds the position id argument.
    public $positionId;

    /**
     * Constructor for the job.
     */
    public function __construct(int $positionId)
    {
        $this->positionId = $positionId;
        $this->position = Position::find($positionId);
    }

    /**
     * Main handler for the job.
     */
    public function handle()
    {
        try {
            $this->validateMandatoryFields();
            $this->computeTotalTradeAmount();
            $this->selectEligibleSymbol();
            $this->updatePositionSide();
            //$this->updateMarginTypeToCrossed();
            $this->setLeverage();
            $this->setLeverageOnToken();

            if (blank($this->position->initial_mark_price)) {
                $this->fetchAndSetMarkPrice();
            }

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

            $this->dispatchOrders($this->position);
        } catch (\Throwable $e) {
            // Update position status to error.
            if ($this->position) {
                $this->position->update(['status' => 'error']);
            }

            throw new TryCatchException(
                throwable: $e,
                additionalData: ['position_id' => $this->positionId]
            );
        }
    }

    /**
     * Ensures that the token margin type is CROSS and not ISOLATED.
     * This is not configurable from the traders' side.
     */
    protected function updateMarginTypeToCrossed()
    {
        $this->position
            ->trader
            ->withRESTApi()
            ->withPosition($this->position)
            ->withExchangeSymbol($this->position->exchangeSymbol)
            ->withOptions([
                'symbol' => $this->position
                                 ->exchangeSymbol
                                 ->symbol
                                 ->token.'USDT',
                'margintype' => 'CROSSED'])
            ->updateMarginType();
    }

    protected function validateMandatoryFields()
    {
        if (blank($this->position->trader_id) ||
            blank($this->position->status) ||
            blank($this->position->trade_configuration)) {
            throw new DispatchPositionException(
                message: "Position ID {$this->position->id} missing mandatory fields",
                additionalData: [
                    'position_id' => $this->position->id,
                ]
            );
        }
    }

    protected function computeTotalTradeAmount()
    {
        $configuration = $this->position->trade_configuration;

        if (blank($this->position->total_trade_amount)) {
            $availableBalance = $this->position->trader
                ->withRESTApi()
                ->withPosition($this->position)
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

    protected function selectEligibleSymbol()
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

    protected function updatePositionSide()
    {
        $this->position->update([
            'side' => $this->position->trade_configuration['positions']['current_side'],
        ]);
    }

    protected function setLeverage()
    {
        if (blank($this->position->leverage)) {
            $wrapper = new ExchangeRESTWrapper(
                new BinanceRESTMapper(credentials: Nidavellir::getSystemCredentials('binance'))
            );

            $leverageData = $this->position
                ->exchangeSymbol
                ->api_notional_and_leverage_symbol_information;

            $possibleLeverage = Nidavellir::getMaximumLeverage(
                $leverageData,
                $this->position->exchangeSymbol->symbol->token.'USDT',
                $this->position->total_trade_amount
            );

            $leverage = min(config('nidavellir.positions.planned_leverage'), $possibleLeverage);
            $this->position->update(['leverage' => $leverage]);
        }
    }

    protected function setLeverageOnToken()
    {
        $this->position->trader
            ->withRESTApi()
            ->withPosition($this->position)
            ->withExchangeSymbol($this->position->exchangeSymbol)
            ->withOptions(['symbol' => $this->position->exchangeSymbol->symbol->token.'USDT', 'leverage' => $this->position->leverage])
            ->setDefaultLeverage();
    }

    protected function fetchAndSetMarkPrice()
    {
        $markPrice = round($this->position->trader
            ->withRESTApi()
            ->withExchangeSymbol($this->position->exchangeSymbol)
            ->withPosition($this->position)
            ->withSymbol($this->position->exchangeSymbol->symbol->token.'USDT')
            ->getMarkPrice(), $this->position->exchangeSymbol->precision_price);

        $this->position->update(['initial_mark_price' => $markPrice]);
    }

    protected function dispatchOrders()
    {
        $marketOrder = $this->position->orders->firstWhere('type', 'MARKET');
        $limitOrders = $this->position->orders->where('type', 'LIMIT');
        $profitOrder = $this->position->orders->firstWhere('type', 'PROFIT');

        $limitJobs = $limitOrders->map(
            fn ($limitOrder) => new DispatchOrderJob($limitOrder->id)
        )->toArray();

        Bus::chain([
            Bus::batch($limitJobs),
            //new DispatchOrderJob($marketOrder->id),
            //new DispatchOrderJob($profitOrder->id),

            //new ConfirmPositionDataQuality($this->position->id)

        ])->dispatch();
    }

    protected function updatePositionError(string $message)
    {
        $this->position->update(['status' => 'error', 'comments' => $message]);
    }
}
