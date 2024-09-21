<?php

namespace Nidavellir\Trading\Jobs\Orders;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\CancelOrderException;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Order;
use Nidavellir\Trading\Models\Trader;

/**
 * This jobs cancels an order given a nidavellir order id.
 * Also updates the order to cancelled.
 *
 * This job also cancels market orders (positions), by
 * opening a market order in the opposite side with the
 * same position amount.
 */
class CancelOrderJob extends AbstractJob
{
    public Order $order;

    public Trader $trader;

    public ExchangeSymbol $exchangeSymbol;

    public $orderId;

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
    }

    public function handle()
    {
        try {
            switch ($this->order->type) {
                case 'LIMIT':
                case 'PROFIT':
                    $this->cancelLimitOrder();
                    break;

                case 'MARKET':
                    $this->cancelPosition();
                    break;
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
            ->withOptions([
                'symbol' => $this->exchangeSymbol->symbol->token.'USDT',
                'orderId' => $this->order->order_exchange_system_id])
            ->withPosition($this->order->position)
            ->withTrader($this->trader)
            ->withExchangeSymbol($this->exchangeSymbol)
            ->withOrder($this->order)
            ->cancelOrder();
    }
}
