<?php

namespace Nidavellir\Trading\Jobs\System;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Models\Order;
use Nidavellir\Trading\Models\Position;

/**
 * RecalculatePositionAverageJob computes the average weighted price
 * for a specific position and updates the profit order.
 *
 * - Locks the position for update to prevent race conditions.
 * - Recalculates the average weighted price for filled orders.
 * - Modifies the profit order with the recalculated price and quantity.
 */
class RecalculatePositionAverageJob extends AbstractJob
{
    // Holds the position ID for which the job is being run.
    protected $positionId;

    // Constructor for the job, passing the position ID.
    public function __construct($positionId)
    {
        $this->positionId = $positionId;
    }

    // Executes the job logic.
    public function handle()
    {
        DB::transaction(function () {
            // Lock the position to prevent race conditions.
            $position = Position::where('id', $this->positionId)
                ->lockForUpdate()
                ->first();

            if ($position) {
                // Perform the weighted average calculation and modify the profit order.
                $this->recalculateAvgWeightPrice($position);
            }
        });
    }

    // Recalculates the average weighted price for the filled orders of the position.
    protected function recalculateAvgWeightPrice(Position $position)
    {
        // Fetch all synced limit orders for this position that are already synced.
        $filledOrdersForPosition = Order::query()
            ->where('position_id', $position->id)
            ->where('status', 'synced')
            ->where('type', 'LIMIT')
            ->get();

        // Initialize variables for calculating average weighted price.
        $totalQuantity = 0;
        $weightedSum = 0;

        // Iterate through the filled orders and calculate the weighted sum.
        foreach ($filledOrdersForPosition as $filledOrder) {
            $orderStatus = $filledOrder->position->trader->withRESTApi()
                ->withLoggable($filledOrder)
                ->withOptions([
                    'symbol' => $filledOrder->position->exchangeSymbol->symbol->token.'USDT',
                    'orderId' => $filledOrder->order_exchange_system_id,
                ])->getOrder();

            // Only consider orders that are filled.
            if (array_key_exists('executedQty', $orderStatus) &&
                $orderStatus['executedQty'] > 0 &&
                $orderStatus['status'] == 'FILLED') {
                $executedQty = (float) $orderStatus['executedQty'];
                $executedPrice = (float) $orderStatus['avgPrice'];

                // Accumulate total quantity and weighted price sum.
                $totalQuantity += $executedQty;
                $weightedSum += $executedQty * $executedPrice;
            }
        }

        // Calculate the new weighted average price.
        if ($totalQuantity > 0) {
            $averageWeightedPrice = $weightedSum / $totalQuantity;
            Log::info('New average weighted price for position '.$position->id.': '.$averageWeightedPrice);

            // Get total quantity from the active position via API.
            $positionData = collect(
                $position->trader
                    ->withRESTApi()
                    ->withOptions([
                        'symbol' => $position->exchangeSymbol->symbol->token.'USDT',
                    ])
                    ->getPositions()
            )
                ->where('symbol', $position->exchangeSymbol->symbol->token.'USDT')
                ->where('positionAmt', '<>', 0)
                ->first();

            $positionQuantity = $positionData['positionAmt'];

            // Find the profit order for this position.
            $profitOrder = $position->orders()
                ->where('status', 'synced')
                ->where('type', 'LIMIT') // Assuming profit orders are also 'LIMIT'.
                ->where('side', $position->side == 'LONG' ? 'SELL' : 'BUY') // Opposite side for profit.
                ->first();

            if ($profitOrder) {
                // Update the local DB for the profit order with the new price and quantity.
                $profitOrder->update([
                    'entry_average_price' => $averageWeightedPrice,
                    'entry_quantity' => $positionQuantity,
                ]);

                Log::info('Updated profit order for position '.$position->id.' with new price and quantity.');

                // Call the API to modify the order with new price and quantity.
                $this->modifyProfitOrder($profitOrder, $averageWeightedPrice, $positionQuantity);
            }
        }
    }

    // Modifies the profit order via API with the new price and quantity.
    protected function modifyProfitOrder(Order $profitOrder, float $newPrice, float $newQuantity)
    {
        $profitOrderStatus = $profitOrder->position->trader->withRESTApi()
            ->withLoggable($profitOrder)
            ->withOptions([
                'symbol' => $profitOrder->position->exchangeSymbol->symbol->token.'USDT',
                'orderId' => $profitOrder->order_exchange_system_id,
                'price' => $newPrice,
                'quantity' => $newQuantity,
            ])
            ->modifyOrder();

        Log::info('Profit order modified via API for order: '.$profitOrder->id, ['response' => $profitOrderStatus]);
    }
}
