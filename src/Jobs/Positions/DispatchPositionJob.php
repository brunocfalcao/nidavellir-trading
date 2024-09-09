<?php

namespace Nidavellir\Trading\Jobs\Positions;

use Illuminate\Support\Facades\Bus;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\PositionNotCreatedException;
use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Jobs\Orders\DispatchOrderJob;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\Nidavellir;
use Throwable;

class DispatchPositionJob extends AbstractJob
{
    public int $positionId;

    public function __construct(int $positionId)
    {
        $this->positionId = $positionId;
    }

    public function handle()
    {
        try {
            $position = Position::find($this->positionId);
            if (! $position) {
                throw new PositionNotCreatedException("Position ID {$this->positionId} not found", $this->positionId);
            }

            $this->validateMandatoryFields($position);

            $configuration = $position->trade_configuration;
            $this->computeTotalTradeAmount($position, $configuration);
            $this->selectEligibleSymbol($position);
            $this->updatePositionSide($position);
            $this->setLeverage($position, $configuration);
            $this->setLeverageOnToken($position);

            if (blank($position->initial_mark_price)) {
                $this->fetchAndSetMarkPrice($position);
            }

            info_multiple(
                '=== POSITION ID '.$position->id,
                'Initial Mark Price: '.$position->initial_mark_price,
                'Leverage: '.$position->leverage,
                'Symbol: '.$position->exchangeSymbol->symbol->token,
                'Trader: '.$position->trader->name,
                'Total Trade Amount: '.$position->total_trade_amount,
                '===',
                ' '
            );

            $this->dispatchOrders($position);
        } catch (Throwable $e) {
            throw new PositionNotCreatedException("Failed to create position with ID: {$this->positionId}", $this->positionId, 0, $e);
        }
    }

    private function validateMandatoryFields(Position $position)
    {
        if (blank($position->trader_id) || blank($position->status) || blank($position->trade_configuration)) {
            throw new PositionNotCreatedException("Position ID {$position->id} missing mandatory fields", $position->id);
        }
    }

    private function computeTotalTradeAmount(Position $position, array $configuration)
    {
        if (blank($position->total_trade_amount)) {
            $availableBalance = $position->trader->withRESTApi()->withPosition($position)->getAccountBalance();
            $minimumTradeAmount = config('nidavellir.positions.minimum_trade_amount');

            if ($availableBalance == 0) {
                $this->updatePositionError($position, 'No USDT on Futures available balance.');

                return;
            }

            if ($availableBalance < $minimumTradeAmount) {
                $this->updatePositionError($position, "Less than {$minimumTradeAmount} USDT on Futures available balance (current: {$availableBalance}).");

                return;
            }

            $maxPercentageTradeAmount = $configuration['positions']['amount_percentage_per_trade'];
            $totalTradeAmount = round(floor($availableBalance * $maxPercentageTradeAmount / 100));

            $position->update(['total_trade_amount' => $totalTradeAmount]);
        }
    }

    private function selectEligibleSymbol(Position $position)
    {
        if (blank($position->exchange_symbol_id)) {
            $eligibleSymbols = ExchangeSymbol::where('is_active', true)
                ->where('is_eligible', true)
                ->where('exchange_id', $position->trader->exchange_id)
                ->get();

            $excludedTokensFromConfig = config('nidavellir.symbols.excluded.tokens');
            $eligibleSymbols = $eligibleSymbols->reject(fn ($symbol) => in_array($symbol->symbol->token, $excludedTokensFromConfig));

            $otherTradeSymbolIds = $position->trader->positions->pluck('exchange_symbol_id')->toArray();
            $eligibleSymbols = $eligibleSymbols->reject(fn ($symbol) => in_array($symbol->id, $otherTradeSymbolIds));

            $exchangeSymbol = $eligibleSymbols->random();
            $position->update(['exchange_symbol_id' => $exchangeSymbol->id]);
        }
    }

    private function updatePositionSide(Position $position)
    {
        $position->update([
            'side' => $position->trade_configuration['positions']['current_side'],
        ]);
    }

    private function setLeverage(Position $position, array $configuration)
    {
        if (blank($position->leverage)) {
            $wrapper = new ExchangeRESTWrapper(
                new BinanceRESTMapper(credentials: Nidavellir::getSystemCredentials('binance'))
            );

            $leverageData = $wrapper
                ->withOptions(['symbol' => $position->exchangeSymbol->symbol->token.'USDT'])
                ->withExchangeSymbol($position->exchangeSymbol)
                ->withPosition($position)
                ->withTrader($position->trader)
                ->getLeverageBracket();

            $possibleLeverage = Nidavellir::getMaximumLeverage(
                $leverageData,
                $position->exchangeSymbol->symbol->token.'USDT',
                $position->total_trade_amount
            );

            $leverage = min(config('nidavellir.positions.planned_leverage'), $possibleLeverage);
            $position->update(['leverage' => $leverage]);
        }
    }

    private function setLeverageOnToken(Position $position)
    {
        $position->trader
            ->withRESTApi()
            ->withPosition($position)
            ->withExchangeSymbol($position->exchangeSymbol)
            ->withOptions(['symbol' => $position->exchangeSymbol->symbol->token.'USDT', 'leverage' => $position->leverage])
            ->setDefaultLeverage();
    }

    private function fetchAndSetMarkPrice(Position $position)
    {
        $markPrice = round($position->trader
            ->withRESTApi()
            ->withExchangeSymbol($position->exchangeSymbol)
            ->withPosition($position)
            ->withSymbol($position->exchangeSymbol->symbol->token.'USDT')
            ->getMarkPrice(), $position->exchangeSymbol->precision_price);

        $position->update(['initial_mark_price' => $markPrice]);
    }

    private function dispatchOrders(Position $position)
    {
        $marketOrder = $position->orders()->firstWhere('orders.type', 'MARKET');
        $limitOrders = $position->orders()->where('orders.type', 'LIMIT')->get();
        $profitOrder = $position->orders()->firstWhere('orders.type', 'PROFIT');

        $limitJobs = $limitOrders->map(fn ($limitOrder) => new DispatchOrderJob($limitOrder->id))->toArray();

        /**
         * The orders are triggered in a certain sequence,
         * first the limit orders, then the market and
         * finally the profit order.
         */
        Bus::chain([
            Bus::batch($limitJobs),
            new DispatchOrderJob($marketOrder->id),
            //new DispatchOrderJob($profitOrder->id),
        ])->dispatch();
    }

    private function updatePositionError(Position $position, string $message)
    {
        $position->update(['status' => 'error', 'comments' => $message]);
    }
}
