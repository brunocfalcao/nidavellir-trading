<?php

namespace Nidavellir\Trading\Jobs\System;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Models\Order;
use Nidavellir\Trading\Models\Position;
use Illuminate\Support\Facades\Log;

/**
 * AdjustAverageWeightPricingProfitOrders manages the adjustment
 * of average weight pricing for profit orders within the system.
 */
class AdjustAverageWeightPricingProfitOrders extends AbstractJob
{
    /**
     * Constructor for initializing any specific properties.
     */
    public function __construct()
    {
        // Initialization logic (if any) goes here.
    }

    /**
     * Executes the core logic for adjusting average weight pricing.
     * This method will handle API calls, computations, and updates.
     */
    protected function compute()
    {
        // Fetch limit orders where both order and position have status 'synced'
        $possibleFilledOrders = Order::query()
            ->where('status', 'synced') // Only orders with status 'synced'
            ->where('type', 'LIMIT') // Only limit orders
            ->whereHas('position', function ($query) {
                $query->where('status', 'synced') // Only positions with status 'synced'
                    ->where(function ($query) {
                        // For SHORT positions: entry_average_price <= markPrice
                        $query->where('side', 'SHORT')
                            ->whereColumn('orders.entry_average_price', '<=', 'positions.initial_mark_price');
                    })
                    ->orWhere(function ($query) {
                        // For LONG positions: entry_average_price >= markPrice
                        $query->where('side', 'LONG')
                            ->whereColumn('orders.entry_average_price', '>=', 'positions.initial_mark_price');
                    });
            })
            ->get();

        // Iterate through each possible filled order
        foreach ($possibleFilledOrders as $order) {
            // Call the API to check if the order is filled
            $orderStatus = $order->position->trader->withRESTApi()
                ->withLoggable($order)
                ->withOptions([
                    'symbol' => $order->position->exchangeSymbol->symbol->token . 'USDT',
                    'orderId' => $order->order_exchange_system_id,
                ])->getOrder();

            // Log the status for debugging
            Log::info('Checking order status for order: ' . $order->id, ['status' => $orderStatus]);

            // Check if the order is filled by verifying 'executedQty' > 0 and 'status' is 'FILLED'
            if (array_key_exists('executedQty', $orderStatus) &&
                $orderStatus['executedQty'] > 0 &&
                $orderStatus['status'] == 'FILLED') {
                // Recalculate the average weighted price for the filled order
                $this->recalculateAvgWeightPrice($order);
            }
        }
    }

    /**
     * Recalculates the average weighted price for filled orders and updates profit order.
     */
    protected function recalculateAvgWeightPrice(Order $order)
    {
        // Fetch all synced limit orders for this position that are already synced
        $filledOrdersForPosition = Order::query()
            ->where('position_id', $order->position_id)
            ->where('status', 'synced')
            ->where('type', 'LIMIT')
            ->get();

        // Initialize variables for calculating average weighted price
        $totalQuantity = 0;
        $weightedSum = 0;

        foreach ($filledOrdersForPosition as $filledOrder) {
            $orderStatus = $filledOrder->position->trader->withRESTApi()
                ->withLoggable($filledOrder)
                ->withOptions([
                    'symbol' => $filledOrder->position->exchangeSymbol->symbol->token . 'USDT',
                    'orderId' => $filledOrder->order_exchange_system_id,
                ])->getOrder();

            // Only consider the orders that are filled
            if (array_key_exists('executedQty', $orderStatus) &&
                $orderStatus['executedQty'] > 0 &&
                $orderStatus['status'] == 'FILLED') {
                $executedQty = (float) $orderStatus['executedQty'];
                $executedPrice = (float) $orderStatus['avgPrice'];

                // Accumulate total quantity and weighted price sum
                $totalQuantity += $executedQty;
                $weightedSum += $executedQty * $executedPrice;
            }
        }

        // Calculate the new weighted average price
        if ($totalQuantity > 0) {
            $averageWeightedPrice = $weightedSum / $totalQuantity;
            Log::info('New average weighted price for position ' . $order->position_id . ': ' . $averageWeightedPrice);

            // Get total quantity from the active position via API
            $positionData = collect(
                $order->position->trader
                    ->withRESTApi()
                    ->withOptions([
                        'symbol' => $order->position->exchangeSymbol->symbol->token . 'USDT',
                    ])
                    ->getPositions()
            )
                ->where('symbol', $order->position->exchangeSymbol->symbol->token . 'USDT')
                ->where('positionAmt', '<>', 0)
                ->first();

            $positionQuantity = $positionData['positionAmt'];

            // Find the profit order for this position
            $profitOrder = $order->position->orders()
                ->where('status', 'synced')
                ->where('type', 'LIMIT') // Assuming profit orders are also 'LIMIT'
                ->where('side', $order->position->side == 'LONG' ? 'SELL' : 'BUY') // Opposite side for profit
                ->first();

            if ($profitOrder) {
                // Update the local DB for the profit order with the new price and quantity
                $profitOrder->update([
                    'entry_average_price' => $averageWeightedPrice,
                    'entry_quantity' => $positionQuantity,
                ]);

                Log::info('Updated profit order for position ' . $order->position_id . ' with new price and quantity.');

                // Call the API to modify the order with new price and quantity
                $this->modifyProfitOrder($profitOrder, $averageWeightedPrice, $positionQuantity);
            }
        }
    }

    /**
     * Modifies the profit order via API with the new price and quantity.
     */
    protected function modifyProfitOrder(Order $profitOrder, float $newPrice, float $newQuantity)
    {
        $profitOrderStatus = $profitOrder->position->trader->withRESTApi()
            ->withLoggable($profitOrder)
            ->withOptions([
                'symbol' => $profitOrder->position->exchangeSymbol->symbol->token . 'USDT',
                'orderId' => $profitOrder->order_exchange_system_id,
                'price' => $newPrice,
                'quantity' => $newQuantity,
            ])
            ->modifyOrder();

        Log::info('Profit order modified via API for order: ' . $profitOrder->id, ['response' => $profitOrderStatus]);
    }
}
