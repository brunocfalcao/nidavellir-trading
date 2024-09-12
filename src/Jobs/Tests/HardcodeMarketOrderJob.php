<?php

namespace Nidavellir\Trading\Jobs\Tests;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\OrderNotSyncedException;
use Nidavellir\Trading\Models\Position;
use Throwable;

class HardcodeMarketOrderJob extends AbstractJob
{
    // Constants for different order types: Market, Limit, and Profit.
    public const ORDER_TYPE_MARKET = 'MARKET';

    public const ORDER_TYPE_LIMIT = 'LIMIT';

    public const ORDER_TYPE_PROFIT = 'PROFIT';

    public $positionId;

    public $order;

    /**
     * Will create a market order just to be able to then
     * create the profit order and test the full cycle.
     *
     * Market order is created without the observers being
     * triggered. The market order will also call dynamically
     * the mark price of the specific token that was selected
     * on the position.
     */
    public function __construct(int $positionId)
    {
        $this->positionId = $positionId;
    }

    public function handle()
    {
        try {
            /**
             * Lets grab all the information we need from several
             * sources.
             */
            $position = Position::find($this->positionId);
            $orderPrice = $position->initial_mark_price;
            $this->order = $position->orders->firstWhere('type', 'MARKET');

            $orderData = [
                'side' => 'buy', // hardcoded for testing.
                'type' => 'MARKET',
                'quantity' => $this->computeOrderAmount($this->order, $orderPrice),
                'symbol' => $this->order
                                 ->position
                                 ->exchangeSymbol
                                 ->symbol
                                 ->token.'USDT',
            ];

            // Update Market order.
            $this->order->update([
                'status' => 'synced',
                'price' => $orderPrice,
                'api_order_id' => rand(1582606909, 1882606909),
            ]);
        } catch (Throwable $e) {
            throw new OrderNotSyncedException(
                message: $e->getMessage()
            );
        }
    }

    private function computeOrderAmount($order, $price)
    {
        $exchangeSymbol = $order->position->exchangeSymbol;

        // Handles both MARKET and LIMIT order types.
        if (in_array($order->type, [self::ORDER_TYPE_MARKET, self::ORDER_TYPE_LIMIT])) {

            /**
             * Calculate the token amount to buy, factoring in
             * leverage and dividing by the configured price.
             */
            $amountAfterDivider = $order->position->total_trade_amount / $order->amount_divider;
            $amountAfterLeverage = $amountAfterDivider * $order->position->leverage;
            $tokenAmountToBuy = $amountAfterLeverage / $price;

            return round($tokenAmountToBuy, $exchangeSymbol->precision_quantity);
        }

        // For PROFIT or other types, return a hardcoded value.
        return 100;
    }
}
