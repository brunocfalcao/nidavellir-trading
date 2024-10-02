<?php

namespace Nidavellir\Trading\Jobs\Positions;

use Illuminate\Support\Str;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\RollbackPositionException;
use Nidavellir\Trading\Jobs\Orders\CancelOrderJob;
use Nidavellir\Trading\Models\Order;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\Models\Trader;

class RollbackPositionJob extends AbstractJob
{
    // ID of the position being validated.
    public int $positionId;

    // The position id.
    public Position $position;

    // The trader.
    public Trader $trader;

    public function __construct(int $positionId)
    {
        $this->positionId = $positionId;
        $this->position = Position::find($positionId);
        $this->trader = $this->position->trader;
    }

    protected function compute()
    {
        /**
         * Position rollback doesn't look to any means. It will
         * immediately execute the rollback no matter how the
         * position is current stated.
         */
        $token = $this->position->exchangeSymbol->symbol->token.'USDT';

        try {
            // Cancel all open orders from this token.
            collect($this->trader
                ->withRESTApi()
                ->withOptions([
                    'symbol' => $token,
                ])
                ->getOpenOrders())
                ->each(function ($order) {
                    \Log::info('Canceling order '.$order['orderId']);
                    CancelOrderJob::dispatch($order['orderId']);
                });

            // If there is an open position, open a contrary market order.
            $cancelPosition = collect(
                $this->trader
                    ->withRESTApi()
                    ->withOptions([
                        'symbol' => $token,
                    ])
                    ->getPositions()
            )
                ->where('symbol', $token)
                ->where('positionAmt', '<>', 0);

            $positionAmount = $cancelPosition->sum('positionAmt');

            if ($positionAmount != 0) {
                $side = $positionAmount < 0 ? 'BUY' : 'SELL';
                $positionAmount = abs($positionAmount);

                // Place contrary order.
                $result = $this->trader
                    ->withRESTApi()
                    ->withLoggable($this->position)
                    ->withOptions([
                        'newClientOrderId' => Str::random(30),
                        'side' => strtoupper($side),
                        'type' => 'MARKET',
                        'quantity' => $positionAmount,
                        'symbol' => $token,
                    ])
                    ->withPosition($this->position)
                    ->withTrader($this->trader)
                    ->withExchangeSymbol($this->position->exchangeSymbol)
                    ->placeSingleOrder();

                $cancelPosition = $cancelPosition->first();

                // We need to add a counter-order just for traceability reasons.
                $counterOrder = Order::create([
                    'position_id' => $this->position->id,
                    'status' => 'synced',
                    'uuid' => (string) Str::uuid(),
                    'type' => 'CANCEL-POSITION',
                    'price_ratio_percentage' => 0,
                    'amount_divider' => 1,
                    'api_result' => $result,
                    'entry_average_price' => $cancelPosition['entryPrice'],
                    'filled_average_price' => $cancelPosition['markPrice'],
                    'filled_quantity' => $positionAmount,
                    'api_result' => $result,
                ]);

                // Update position PnL.
                $this->position->update([
                    'status' => 'cancelled',
                    'unrealized_pnl' => $cancelPosition['unRealizedProfit'],
                ]);
            }

            $this->position->update([
                'status' => 'cancelled',
                'closed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            throw new RollbackPositionException(throwable: $e);
        }
    }
}
