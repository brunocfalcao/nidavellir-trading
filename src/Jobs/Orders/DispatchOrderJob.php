<?php

namespace Nidavellir\Trading\Jobs\Orders;

use Illuminate\Support\Str;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\DispatchOrderException;
use Nidavellir\Trading\Models\ApiSystem;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Order;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Models\Trader;

/**
 * DispatchOrderJob handles the dispatching of trading orders
 * (Market, Limit, Profit). It processes orders based on type,
 * manages retry logic, handles sibling orders, and logs key
 * information for debugging. Ensures orders are processed
 * and dispatched to the exchange, adjusting parameters based
 * on market conditions and configurations.
 *
 * - Processes Market, Limit, and Profit orders.
 * - Manages retry logic and sibling dependencies.
 * - Logs significant actions and updates order status.
 */
class DispatchOrderJob extends AbstractJob
{
    public const ORDER_TYPE_MARKET = 'MARKET';

    public const ORDER_TYPE_LIMIT = 'LIMIT';

    public const ORDER_TYPE_PROFIT = 'PROFIT';

    public const ORDER_TYPE_POSITION_CANCELLATION = 'POSITION-CANCELLATION';

    public Order $order;

    public $orderId;

    public Trader $trader;

    public Position $position;

    public ExchangeSymbol $exchangeSymbol;

    public ApiSystem $apiSystem;

    public Symbol $symbol;

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
        $this->order = Order::find($orderId);
        $this->trader = $this->order->position->trader;
        $this->position = $this->order->position;
        $this->exchangeSymbol = $this->order->position->exchangeSymbol;
        $this->symbol = $this->exchangeSymbol->symbol;
        $this->exchange = $this->order->position->trader->exchange;
    }

    protected function executeApiLogic()
    {
        $this->attachRelatedModel($this->order);

        $siblings = $this->position->orders->where('id', '<>', $this->order->id);
        $siblingsLimitOnly = $siblings->where('type', self::ORDER_TYPE_LIMIT);

        if ($this->attempts() == 3) {
            $this->order->update(['status' => 'error']);
            throw new DispatchOrderException(
                message: 'Max attempts: Failed to create order on exchange',
                additionalData: ['order_id' => $this->order->id],
            );
        }

        if ($this->order->status == 'error') {
            return;
        }

        if ($this->order->type == self::ORDER_TYPE_POSITION_CANCELLATION) {
            $this->syncPositionCancellationOrder();

            return;
        }

        if (! $siblings->contains('status', 'error') &&
            ! $this->shouldWaitForLimitOrdersToFinish($siblingsLimitOnly) &&
            ! $this->shouldWaitForAllOrdersExceptProfit($siblings)) {
            $this->processOrder();
        }
        info('Marking Job Order '.$this->order->id.' as completed.');
    }

    protected function syncPositionCancellationOrder()
    {
        $positions = $this->trader
            ->withRESTApi()
            ->withLoggable($this->order)
            ->getPositions();

        $positionAmount = collect($positions)
            ->where('positionAmt', '<>', 0)
            ->where(
                'symbol',
                $this->exchangeSymbol->symbol->token.'USDT'
            )
            ->sum('positionAmt');

        $side = $positionAmount < 0 ? 'LONG' : 'SHORT';
        $positionAmount = abs($positionAmount);

        $sideDetails = $this->getOrderSideDetails($side);
        $this->placePositionCancellationOrder($positionAmount, $sideDetails);
    }

    protected function shouldWaitForLimitOrdersToFinish($siblingsLimitOnly)
    {
        if ($this->order->type === self::ORDER_TYPE_MARKET &&
            $siblingsLimitOnly->contains('status', 'new')) {
            $this->release(5);

            return true;
        }

        return false;
    }

    protected function shouldWaitForAllOrdersExceptProfit($siblings)
    {
        if ($this->order->type === self::ORDER_TYPE_PROFIT &&
            $siblings->contains('status', 'new')) {
            $this->release(5);

            return true;
        }

        return false;
    }

    protected function processOrder()
    {
        $sideDetails = $this->getOrderSideDetails($this->exchangeSymbol->side);
        $orderPrice = $this->getPriceByRatio();
        $orderQuantity = $this->computeOrderQuantity($orderPrice);
        $this->dispatchOrder($orderPrice, $orderQuantity, $sideDetails);
    }

    protected function computeOrderQuantity($price)
    {
        if (in_array($this->order->type, [self::ORDER_TYPE_MARKET, self::ORDER_TYPE_LIMIT])) {
            return round(
                ($this->position->total_trade_amount / $this->order->amount_divider * $this->position->leverage) / $price,
                $this->exchangeSymbol->precision_quantity
            );
        }

        if ($this->order->type == self::ORDER_TYPE_PROFIT) {
            return $this->order
                ->position
                ->orders
                ->firstWhere('type', 'MARKET')->filled_quantity;
        }
    }

    protected function dispatchOrder($orderPrice, $orderQuantity, $sideDetails)
    {
        switch ($this->order->type) {
            case self::ORDER_TYPE_LIMIT:
                $this->placeLimitOrder($orderPrice, $orderQuantity, $sideDetails);
                break;
            case self::ORDER_TYPE_MARKET:
                $this->placeMarketOrder($orderQuantity, $sideDetails);
                break;
            case self::ORDER_TYPE_PROFIT:
                $this->placeProfitOrder($orderPrice, $orderQuantity, $sideDetails);
                break;
        }
    }

    protected function placePositionCancellationOrder($orderQuantity, $sideDetails)
    {
        $this->orderData = [
            'newClientOrderId' => $this->generateClientOrderId(),
            'side' => strtoupper($sideDetails['orderMarketSide']),
            'type' => 'MARKET',
            'quantity' => $orderQuantity,
            'symbol' => $this->symbol->token.'USDT',
        ];

        $result = $this->trader
            ->withRESTApi()
            ->withLoggable($this->order)
            ->withOptions($this->orderData)
            ->withPosition($this->position)
            ->withTrader($this->trader)
            ->withExchangeSymbol($this->exchangeSymbol)
            ->withOrder($this->order)
            ->placeSingleOrder();

        $this->updateOrderWithExchangeResult($result);
        $this->updateOrderWithQueryOnOrderId($this->order->id);

        $this->order
            ->position
            ->orders()
            ->where('type', 'PROFIT')
            ->update(['status' => 'cancelled']);
    }

    protected function placeMarketOrder($orderQuantity, $sideDetails)
    {
        $this->orderData = [
            'newClientOrderId' => $this->generateClientOrderId(),
            'side' => strtoupper($sideDetails['orderMarketSide']),
            'type' => 'MARKET',
            'quantity' => $orderQuantity,
            'symbol' => $this->symbol->token.'USDT',
        ];

        $result = $this->trader
            ->withRESTApi()
            ->withLoggable($this->order)
            ->withOptions($this->orderData)
            ->withPosition($this->position)
            ->withTrader($this->trader)
            ->withExchangeSymbol($this->exchangeSymbol)
            ->withOrder($this->order)
            ->placeSingleOrder();

        $this->updateOrderWithExchangeResult($result);
        $this->updateOrderWithQueryOnOrderId($this->order->id);
    }

    protected function updateOrderWithQueryOnOrderId($orderId)
    {
        $this->order->refresh();

        $result = $this->trader
            ->withRESTApi()
            ->withLoggable($this->order)
            ->withPosition($this->position)
            ->withTrader($this->trader)
            ->withExchangeSymbol($this->exchangeSymbol)
            ->withOrder($this->order)
            ->withOptions([
                'symbol' => $this->symbol->token.'USDT',
                'orderId' => $this->order->order_exchange_system_id,
            ])
            ->getOrder();

        if ($result['status'] != 'FILLED') {
            throw new DispatchOrderException(
                message: 'Market order was executed but it is not FILLED',
                additionalData: ['order_id' => $this->order->id],
            );
        }

        $this->order->update([
            'filled_quantity' => $result['executedQty'],
            'filled_average_price' => $result['avgPrice'],
        ]);
    }

    protected function generateClientOrderId()
    {
        return Str::random(30);
    }

    protected function placeLimitOrder($orderPrice, $orderQuantity, $sideDetails)
    {
        $this->order->update([
            'entry_average_price' => $orderPrice,
            'entry_quantity' => $orderQuantity,
        ]);

        $this->orderData = [
            'timeInForce' => 'GTC',
            'side' => strtoupper($sideDetails['orderLimitBuy']),
            'type' => 'LIMIT',
            'quantity' => $orderQuantity,
            'newClientOrderId' => $this->generateClientOrderId(),
            'symbol' => $this->exchangeSymbol->symbol->token.'USDT',
            'price' => $orderPrice,
        ];

        $result = $this->trader
            ->withRESTApi()
            ->withLoggable($this->order)
            ->withOptions($this->orderData)
            ->withPosition($this->position)
            ->withTrader($this->trader)
            ->withExchangeSymbol($this->exchangeSymbol)
            ->withOrder($this->order)
            ->placeSingleOrder();

        $this->updateOrderWithExchangeResult($result);
    }

    protected function placeProfitOrder($orderPrice, $orderQuantity, $sideDetails)
    {
        $this->order->update([
            'entry_average_price' => $orderPrice,
        ]);

        $this->orderData = [
            'timeInForce' => 'GTC',
            'side' => strtoupper($sideDetails['orderLimitProfit']),
            'type' => 'LIMIT',
            'reduceOnly' => 'true',
            'quantity' => $orderQuantity,
            'newClientOrderId' => $this->generateClientOrderId(),
            'symbol' => $this->exchangeSymbol->symbol->token.'USDT',
            'price' => $orderPrice,
        ];

        $result = $this->trader
            ->withRESTApi()
            ->withLoggable($this->order)
            ->withOptions($this->orderData)
            ->withPosition($this->position)
            ->withTrader($this->trader)
            ->withExchangeSymbol($this->exchangeSymbol)
            ->withOrder($this->order)
            ->placeSingleOrder();

        $this->updateOrderWithExchangeResult($result);
    }

    protected function updateOrderWithExchangeResult(array $result)
    {
        $this->order->update([
            'order_exchange_system_id' => $result['orderId'],
            'filled_quantity' => $result['executedQty'],
            'filled_average_price' => $result['avgPrice'],
            'api_result' => $result,
            'status' => 'synced',
        ]);
    }

    protected function getOrderSideDetails($side)
    {
        if ($side == 'LONG') {
            return [
                'orderMarketSide' => 'BUY',
                'orderLimitBuy' => 'BUY',
                'orderLimitProfit' => 'SELL',
            ];
        }

        if ($side == 'SHORT') {
            return [
                'orderMarketSide' => 'SELL',
                'orderLimitBuy' => 'SELL',
                'orderLimitProfit' => 'BUY',
            ];
        }

        throw new DispatchOrderException(
            message: 'Symbol side empty, null, or unknown',
            additionalData: ['order_id' => $this->order->id]
        );
    }

    protected function getPriceByRatio()
    {
        $markPrice = $this->position->initial_mark_price;
        $precision = $this->exchangeSymbol->precision_price;
        $priceRatio = $this->order->price_ratio_percentage / 100;
        $side = $this->position->side;

        $orderPrice = 0;

        if ($side === 'LONG') {
            $orderPrice = $this->order->type !== self::ORDER_TYPE_PROFIT
                ? round($markPrice - ($markPrice * $priceRatio), $precision)
                : round($markPrice + ($markPrice * $priceRatio), $precision);
        }

        if ($side === 'SHORT') {
            $orderPrice = $this->order->type !== self::ORDER_TYPE_PROFIT
                ? round($markPrice + ($markPrice * $priceRatio), $precision)
                : round($markPrice - ($markPrice * $priceRatio), $precision);
        }

        return $this->adjustPriceToTickSize($orderPrice, $this->exchangeSymbol->tick_size);
    }

    protected function adjustPriceToTickSize($price, $tickSize)
    {
        return floor($price / $tickSize) * $tickSize;
    }
}
