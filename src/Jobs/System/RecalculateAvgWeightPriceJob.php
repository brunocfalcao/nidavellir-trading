<?php

namespace Nidavellir\Trading\Jobs\System;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Models\Order;
use Nidavellir\Trading\Models\Position;

/**
 * RecalculateAvgWeightPriceJob recalculates the average weighted price for
 * a position's filled limit orders and updates the profit order accordingly.
 */
class RecalculateAvgWeightPriceJob extends AbstractJob
{
    protected $positionId;

    /**
     * Constructor for the job, passing the position ID.
     */
    public function __construct($positionId)
    {
        $this->positionId = $positionId;
        Log::info('Job initialized for position: '.$positionId);
    }

    /**
     * Core logic for recalculating the weighted average price.
     */
    protected function compute()
    {
        Log::info('Starting transaction for recalculating position: '.$this->positionId);

        DB::transaction(function () {
            // Lock the position to prevent race conditions.
            $position = Position::where('id', $this->positionId)
                ->lockForUpdate()
                ->first();

            if ($position) {
                Log::info('Locked position: '.$position->id);

                // Update position status temporary to 'recalculating'.
                $position->update(['status' => 'locked']);

                // Recalculate weighted average price and update the profit order.
                $this->recalculateAvgWeightPrice($position);

                // Re-update position status to synced.
                $position->update(['status' => 'synced']);
            } else {
                Log::warning('Position not found or already locked: '.$this->positionId);
            }
        });

        Log::info('Transaction completed for position: '.$this->positionId);
    }

    /**
     * Recalculates the average weighted price for the filled orders of the position.
     */
    protected function recalculateAvgWeightPrice(Position $position)
    {
        Log::info('Recalculating weighted average price for position: '.$position->id);

        // Fetch all synced limit and market orders for this position from Binance.
        $filledOrdersForPosition = Order::query()
            ->where('position_id', $position->id)
            ->where('status', 'synced')
            ->whereIn('type', ['LIMIT', 'MARKET'])
            ->get();

        Log::info('Fetched '.$filledOrdersForPosition->count().' synced LIMIT and MARKET orders for position: '.$position->id);

        // Initialize variables for calculating the average weighted price.
        $totalQuantity = 0;
        $weightedSum = 0;

        foreach ($filledOrdersForPosition as $filledOrder) {
            // Get the order status from Binance API.
            $orderStatus = $filledOrder->position->trader->withRESTApi()
                ->withLoggable($filledOrder)
                ->withOptions([
                    'symbol' => $filledOrder->position->exchangeSymbol->symbol->token.'USDT',
                    'orderId' => $filledOrder->order_exchange_system_id,
                ])->getOrder();

            Log::info('Order status retrieved for order: '.$filledOrder->id, ['status' => $orderStatus]);

            // Only consider the orders that are filled.
            if (array_key_exists('executedQty', $orderStatus) &&
                $orderStatus['executedQty'] > 0 &&
                $orderStatus['status'] == 'FILLED') {
                $executedQty = (float) $orderStatus['executedQty'];
                $executedPrice = (float) $orderStatus['avgPrice'];

                // Update the order status to 'filled'.
                $filledOrder->update(['status' => 'filled']);
                Log::info('Order '.$filledOrder->id.' updated to FILLED (average price: '.$orderStatus['avgPrice'].' quantity: '.$orderStatus['executedQty'].')');

                // Accumulate total quantity and weighted price sum.
                $totalQuantity += $executedQty;
                $weightedSum += $executedQty * $executedPrice;

                // Log the cumulative quantities and weighted sums after each iteration
                Log::info('Accumulated total quantity: '.$totalQuantity);
                Log::info('Accumulated weighted sum: '.$weightedSum);
            }
        }

        // Calculate the new weighted average price.
        if ($totalQuantity > 0) {
            $averageWeightedPrice = $weightedSum / $totalQuantity;
            Log::info('New average weighted price for position '.$position->id.': '.$averageWeightedPrice);

            // Retrieve the profit percentage from the position.
            $profitPercentage = $position->initial_profit_percentage_ratio / 100; // Convert to decimal form.
            Log::info('Profit percentage retrieved: '.$profitPercentage);

            // Calculate the new profit price based on the position side and profit percentage.
            $newProfitPrice = $this->calculateProfitPrice($averageWeightedPrice, $profitPercentage, $position->side);
            Log::info('New profit price for position '.$position->id.': '.$newProfitPrice);

            // Get the profit order for this position.
            $profitOrder = $position->orders()
                ->where('status', 'synced')
                ->where('type', 'PROFIT') // Profit orders have type 'PROFIT'
                ->first();

            if ($profitOrder) {
                Log::info('Profit order found for position: '.$position->id.' with order ID: '.$profitOrder->id);

                // Update the local DB for the profit order with the new filled price and total quantity.
                $profitOrder->update([
                    'filled_average_price' => $averageWeightedPrice,
                    'filled_quantity' => $totalQuantity,
                ]);

                Log::info('Updated profit order for position '.$position->id.' with new filled price and total quantity.');

                // Sanitize the price and quantity with exchange precision.
                $precisionPrice = $position->exchangeSymbol->precision_price;
                $precisionQuantity = $position->exchangeSymbol->precision_quantity;

                $sanitizedPrice = round($newProfitPrice, $precisionPrice);
                $sanitizedQuantity = round($totalQuantity, $precisionQuantity);

                Log::info('Sanitized price: '.$sanitizedPrice.', Sanitized quantity: '.$sanitizedQuantity);

                // Determine the side for the profit order based on the position side.
                $side = $position->side === 'LONG' ? 'SELL' : 'BUY';
                Log::info('Setting order side to: '.$side);

                // Call the API to modify the order with new price, quantity, and side.
                $this->modifyProfitOrder($profitOrder, $sanitizedPrice, $sanitizedQuantity, $side);
            } else {
                Log::warning('No profit order found for position: '.$position->id);
            }
        } else {
            Log::warning('No valid filled orders to calculate weighted average price for position: '.$position->id);
        }
    }

    /**
     * Calculates the new profit price based on the weighted average price, profit percentage, and position side.
     */
    protected function calculateProfitPrice(float $averageWeightedPrice, float $profitPercentage, string $side): float
    {
        Log::info('Calculating profit price for side: '.$side.' with profit percentage: '.$profitPercentage);

        if ($side === 'LONG') {
            $newPrice = $averageWeightedPrice * (1 + $profitPercentage);
            Log::info('New price for LONG position: '.$newPrice);

            return $newPrice;
        } else {
            $newPrice = $averageWeightedPrice * (1 - $profitPercentage);
            Log::info('New price for SHORT position: '.$newPrice);

            return $newPrice;
        }
    }

    /**
     * Modifies the profit order via API with the new price, quantity, and side.
     */
    protected function modifyProfitOrder(Order $profitOrder, float $newPrice, float $newQuantity, string $side)
    {
        // Retrieve the tick size from the exchange symbol
        $tickSize = $profitOrder->position->exchangeSymbol->tick_size;

        // Adjust the new price based on the tick size
        $adjustedPrice = $this->adjustPriceToTickSize($newPrice, $tickSize);

        Log::info('Modifying profit order: '.$profitOrder->id.' with new price: '.$adjustedPrice.', quantity: '.$newQuantity.', and side: '.$side);

        // Send the adjusted price to the API
        $profitOrderStatus = $profitOrder->position->trader->withRESTApi()
            ->withLoggable($profitOrder)
            ->withOptions([
                'symbol' => $profitOrder->position->exchangeSymbol->symbol->token.'USDT',
                'orderId' => $profitOrder->order_exchange_system_id,
                'price' => $adjustedPrice, // Use the adjusted price
                'quantity' => $newQuantity,
                'side' => $side,
            ])
            ->modifyOrder();

        Log::info('Profit order modified via API for order: '.$profitOrder->id, ['response' => $profitOrderStatus]);
    }

    protected function adjustPriceToTickSize($price, $tickSize)
    {
        return floor($price / $tickSize) * $tickSize;
    }
}
