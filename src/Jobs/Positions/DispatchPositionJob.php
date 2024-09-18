<?php

namespace Nidavellir\Trading\Jobs\Positions;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Jobs\Orders\DispatchOrderJob;
use Nidavellir\Trading\Jobs\Tests\HardcodeMarketOrderJob;
use Nidavellir\Trading\Models\ApplicationLog;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\Nidavellir;
use Nidavellir\Trading\NidavellirException;
use Throwable;

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

    private $logBlock;

    /**
     * Constructor for the job.
     */
    public function __construct(int $positionId)
    {
        $this->position = Position::find($positionId);
        $this->logBlock = Str::uuid();
    }

    /**
     * Main handler for the job.
     */
    public function handle()
    {
        ApplicationLog::withActionCanonical('Position.Dispatch.Start')
            ->withDescription('Dispatch position job started')
            ->withPositionId($this->position->id)
            ->withBlock($this->logBlock)
            ->saveLog();

        try {
            $this->validateMandatoryFields();
            $this->computeTotalTradeAmount();
            $this->selectEligibleSymbol();
            $this->updatePositionSide();
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

            ApplicationLog::withActionCanonical('Position.Dispatch.End')
                ->withDescription('Dispatch position job completed successfully')
                ->withPositionId($this->position->id)
                ->withBlock($this->logBlock)
                ->saveLog();
        } catch (Throwable $e) {
            ApplicationLog::withActionCanonical('Position.Dispatch.Error')
                ->withDescription('Error occurred during position dispatch')
                ->withReturnData(['error' => $e->getMessage()])
                ->withPositionId($this->position->id)
                ->withBlock($this->logBlock)
                ->saveLog();

            throw new NidavellirException(
                originalException: $e,
                title: 'Error during dispatching position ID: '.$this->position->id,
                loggable: $this->position
            );
        }
    }

    private function validateMandatoryFields()
    {
        if (blank($this->position->trader_id) ||
            blank($this->position->status) ||
            blank($this->position->trade_configuration)) {
            throw new NidavellirException(
                title: "Position ID {$this->position->id} missing mandatory fields",
                loggable: $this->position
            );
        }
    }

    private function computeTotalTradeAmount()
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

            ApplicationLog::withActionCanonical('Position.Dispatch.TradeAmountComputed')
                ->withDescription('Total trade amount computed')
                ->withReturnData(['total_trade_amount' => $totalTradeAmount])
                ->withPositionId($this->position->id)
                ->withBlock($this->logBlock)
                ->saveLog();
        }
    }

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

            ApplicationLog::withActionCanonical('Position.Dispatch.SymbolSelected')
                ->withDescription('Eligible symbol selected for the position')
                ->withReturnData(['symbol_token' => $exchangeSymbol->symbol->token])
                ->withPositionId($this->position->id)
                ->withBlock($this->logBlock)
                ->saveLog();
        }
    }

    private function updatePositionSide()
    {
        $this->position->update([
            'side' => $this->position->trade_configuration['positions']['current_side'],
        ]);

        ApplicationLog::withActionCanonical('Position.Dispatch.SideUpdated')
            ->withDescription('Position side updated')
            ->withReturnData(['side' => $this->position->side])
            ->withPositionId($this->position->id)
            ->withBlock($this->logBlock)
            ->saveLog();
    }

    private function setLeverage()
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

            ApplicationLog::withActionCanonical('Position.Dispatch.LeverageSet')
                ->withDescription('Leverage set for the position')
                ->withReturnData(['leverage' => $leverage])
                ->withPositionId($this->position->id)
                ->withBlock($this->logBlock)
                ->saveLog();
        }
    }

    private function setLeverageOnToken()
    {
        $this->position->trader
            ->withRESTApi()
            ->withPosition($this->position)
            ->withExchangeSymbol($this->position->exchangeSymbol)
            ->withOptions(['symbol' => $this->position->exchangeSymbol->symbol->token.'USDT', 'leverage' => $this->position->leverage])
            ->setDefaultLeverage();

        ApplicationLog::withActionCanonical('Position.Dispatch.LeverageOnTokenSet')
            ->withDescription('Leverage set on token for the position')
            ->withPositionId($this->position->id)
            ->withBlock($this->logBlock)
            ->saveLog();
    }

    private function fetchAndSetMarkPrice()
    {
        $markPrice = round($this->position->trader
            ->withRESTApi()
            ->withExchangeSymbol($this->position->exchangeSymbol)
            ->withPosition($this->position)
            ->withSymbol($this->position->exchangeSymbol->symbol->token.'USDT')
            ->getMarkPrice(), $this->position->exchangeSymbol->precision_price);

        $this->position->update(['initial_mark_price' => $markPrice]);

        ApplicationLog::withActionCanonical('Position.Dispatch.MarkPriceSet')
            ->withDescription('Initial mark price set for the position')
            ->withReturnData(['mark_price' => $markPrice])
            ->withPositionId($this->position->id)
            ->withBlock($this->logBlock)
            ->saveLog();
    }

    private function dispatchOrders()
    {
        $marketOrder = $this->position->orders->firstWhere('type', 'MARKET');
        $limitOrders = $this->position->orders->where('type', 'LIMIT');
        $profitOrder = $this->position->orders->firstWhere('type', 'PROFIT');

        $limitJobs = $limitOrders->map(
            fn ($limitOrder) => new DispatchOrderJob($limitOrder->id)
        )->toArray();

        Bus::chain([
            Bus::batch($limitJobs),

            // Lets hardcode a market order, so we don't spend money.
            new HardcodeMarketOrderJob($this->position->id),

            // Now the profit order, also simulated for now.
            new DispatchOrderJob($profitOrder->id),

        ])->dispatch();

        ApplicationLog::withActionCanonical('Position.Dispatch.OrdersDispatched')
            ->withDescription('Position orders dispatched')
            ->withPositionId($this->position->id)
            ->withBlock($this->logBlock)
            ->saveLog();
    }

    private function updatePositionError(string $message)
    {
        $this->position->update(['status' => 'error', 'comments' => $message]);

        ApplicationLog::withActionCanonical('Position.Dispatch.Error')
            ->withDescription('Position marked as error')
            ->withReturnData(['error_message' => $message])
            ->withPositionId($this->position->id)
            ->withBlock($this->logBlock)
            ->saveLog();
    }
}
