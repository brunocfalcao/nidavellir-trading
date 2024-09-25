<?php

namespace Nidavellir\Trading\Jobs\Orders;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\CancelOrderException;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Order;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Models\Trader;

/**
 * This jobs cancels an order given a nidavellir order id.
 * It can also cancel the market order (position).
 * Also updates the order to cancelled, and in case of
 * the position cancellation, a POSITION-CANCELLATION
 * order type is created on the system.
 *
 * To cancel market orders (positions), it
 * opens a market order in the opposite side with the
 * same position amount.
 */
class CancelOrderJob extends AbstractJob
{
    private Order $order;

    private Trader $trader;

    private ExchangeSymbol $exchangeSymbol;

    private Symbol $symbol;

    private $orderId;

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;

        $this->order = Order::find($orderId);

        if (! $this->order) {
            throw new CancelOrderException(
                message: 'Cancel Order error - Order not found',
                additionalData: ['order_id' => $orderId]
            );
        }

        $this->trader = $this->order->position->trader;
        $this->exchangeSymbol = $this->order->position->exchangeSymbol;
        $this->symbol = $this->exchangeSymbol->symbol;
    }

    public function handle()
    {
        try {
            if (blank($this->order->order_exchange_system_id)) {
                return;
            }

            // Query order to understand if it's filled or not.
            $result = $this->trader->withRESTApi()
                ->withLoggable($this->order)
                ->withOptions([
                    'symbol' => $this->symbol->token.'USDT',
                    'orderId' => $this->order->order_exchange_system_id,
                ])->getOrder();

            if ($result['executedQty'] > 0) {
                $this->cancelPosition();
            } else {
                $this->cancelLimitOrder();
            }
            $this->order->update(['status' => 'cancelled']);
        } catch (\Throwable $e) {
            throw new TryCatchException(
                throwable: $e,
                additionalData: [
                    'order_id' => $this->orderId]
            );
        }
    }

    /**
     * Cancels the position amount. Meaning, it's a market order
     * that needs to be cancelled, and on this case we need to
     * open an order with the same position amount but in the
     * opposite direction.
     */
    protected function cancelPosition()
    {
        /**
         * Now let's open an order contrary to the current position side.
         * This order is special, it's type is 'position-cancellation'.
         */

        // Get the pre-payload to create the opposite order.
        $cancellationOrder = Order::create([
            'position_id' => $this->order->position->id,
            'status' => 'new',
            'type' => 'POSITION-CANCELLATION',
            'price_ratio_percentage' => 0,
            'amount_divider' => 1,
            'entry_quantity' => 0, // Later is is changed in the dispatch order.
        ]);

        DispatchOrderJob::dispatch($cancellationOrder->id);
    }

    /**
     * Cancels limit orders. It's as easy as to send a cancellation
     * request, because the order is not filled yet.
     */
    protected function cancelLimitOrder()
    {
        $this->trader
            ->withRESTApi()
            ->withLoggable($this->order)
            ->withOptions([
                'symbol' => $this->exchangeSymbol->symbol->token.'USDT',
                'orderId' => $this->order->order_exchange_system_id])
            ->cancelOrder();
    }
}
