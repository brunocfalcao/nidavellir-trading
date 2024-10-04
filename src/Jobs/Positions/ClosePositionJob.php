<?php

namespace Nidavellir\Trading\Jobs\Positions;

use Illuminate\Support\Facades\DB;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Models\Position;

class ClosePositionJob extends AbstractJob
{
    protected int $positionId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $positionId)
    {
        $this->positionId = $positionId;
    }

    /**
     * Execute the core logic of closing a position.
     * Inherited from AbstractJob.
     */
    protected function compute()
    {
        DB::transaction(function () {
            $position = Position::where('id', $this->positionId)
                ->lockForUpdate() // Apply a lock to prevent concurrent modifications
                ->first();

            if (! $position) {
                return; // If no position is found, abort.
            }

            // Update position status temporary to 'recalculating'.
            $position->update(['status' => 'locked']);

            // Get all synced limit orders tied to this position
            $pendingLimitOrders = $position->orders()
                ->where('type', 'LIMIT')
                ->where('status', 'synced')
                ->lockForUpdate() // Lock orders for the update
                ->get();

            if ($pendingLimitOrders->isEmpty()) {
                return; // No pending limit orders to cancel
            }

            // Cancel all open limit orders using the REST API
            $position->trader->withRESTApi()
                ->withOptions([
                    'symbol' => $position->exchangeSymbol->symbol->token.'USDT', // e.g., ADAUSDT
                ])
                ->cancelOpenOrders(); // Cancels all open limit orders for the symbol

            // Update the order status for all synced limit orders in a batch
            $position->orders()
                ->where('type', 'LIMIT')
                ->where('status', 'synced')
                ->update(['status' => 'cancelled']);

            $token = $position->exchangeSymbol->symbol->token.'USDT';

            // Fetch position data from the API for PnL record.
            $positionData = collect(
                $position->trader
                    ->withRESTApi()
                    ->withOptions([
                        'symbol' => $token,
                    ])
                    ->getPositions()
            )
                ->where('symbol', $token)
                ->where('positionAmt', '<>', 0)
                ->first();

            $profitOrder = $position->orders->firstWhere('type', 'PROFIT');

            // Update profit order to filled.
            $profitOrder->update(['status' => 'filled']);

            // Update position status and unrealized PnL
            $position->update([
                'status' => 'closed',
                'unrealized_pnl' => $positionData['unRealizedProfit'] ?? 0,
            ]);

            // Dispatch a new position.
            $positionData = [
                'trader_id' => $position->trader->id,
                'total_trade_amount' => 20,
            ];

            Position::create($positionData);
        });
    }
}
