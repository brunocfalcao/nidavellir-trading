<?php

namespace Nidavellir\Trading\Jobs\Orders;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\CancelOrderException;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Order;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Models\Trader;

/**
 * Class: CancelOrderJob
 *
 * This job handles the process of cancelling an order on the exchange.
 * It checks if the order is partially filled and either cancels the
 * entire position or cancels the limit order based on its state.
 *
 * Important points:
 * - Validates if the order exists before processing.
 * - Cancels either the whole position or the individual limit order.
 */
class CancelOrderJob extends AbstractJob
{
    // The order instance being cancelled.
    private Order $order;

    // The trader associated with the order.
    private Trader $trader;

    // The exchange symbol associated with the order.
    private ExchangeSymbol $exchangeSymbol;

    // The symbol representing the asset being traded.
    private Symbol $symbol;

    // The exchange order id, NOT the orders.id!
    private $exchangeSystemOrderId;

    public function __construct(int $exchangeSystemOrderId)
    {
        $this->exchangeSystemOrderId = $exchangeSystemOrderId;
        $this->order = Order::firstWhere('order_exchange_system_id', $exchangeSystemOrderId);

        // Check if the order exists; throw an exception if not found.
        if (! $this->order) {
            throw new CancelOrderException(
                message: 'Cancel Order error - Order not found or not part of Nidavellir',
                additionalData: ['order_id' => $exchangeSystemOrderId]
            );
        }

        // Set the trader, exchange symbol, and symbol attributes.
        $this->trader = $this->order->position->trader;
        $this->exchangeSymbol = $this->order->position->exchangeSymbol;
        $this->symbol = $this->exchangeSymbol->symbol;
    }

    // Main method that processes the job's logic inherited from AbstractJob.
    protected function compute()
    {
        // Attach the order model to jobQueueEntry for better tracking.
        $this->attachRelatedModel($this->order);

        // If the order does not have an exchange system ID, return early.
        if (blank($this->order->order_exchange_system_id)) {
            return;
        }

        // Fetch the current status of the order from the exchange.
        $orderToCancel = $this->trader->withRESTApi()
            ->withLoggable($this->order)
            ->withOptions([
                'symbol' => $this->symbol->token.'USDT',
                'orderId' => $this->order->order_exchange_system_id,
            ])->getOrder();

        \Log::info($orderToCancel);

        if (array_key_exists('origQty', $orderToCancel) &&
            $orderToCancel['origQty'] != 0 &&
            $orderToCancel['status'] == 'NEW') {
            $this->cancelLimitOrder();
        }

        // Update the order status to 'cancelled'.
        $this->order->update(['status' => 'cancelled']);
    }

    // Cancels the limit order on the exchange using the trader's API.
    protected function cancelLimitOrder()
    {
        $this->trader
            ->withRESTApi()
            ->withLoggable($this->order)
            ->withOptions([
                'symbol' => $this->exchangeSymbol->symbol->token.'USDT',
                'orderId' => $this->order->order_exchange_system_id,
            ])->cancelOrder();
    }
}
