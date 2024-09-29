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

    // The ID of the order being processed.
    private $orderId;

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
        $this->order = Order::find($orderId);

        // Check if the order exists; throw an exception if not found.
        if (!$this->order) {
            throw new CancelOrderException(
                message: 'Cancel Order error - Order not found',
                additionalData: ['order_id' => $orderId]
            );
        }

        // Set the trader, exchange symbol, and symbol attributes.
        $this->trader = $this->order->position->trader;
        $this->exchangeSymbol = $this->order->position->exchangeSymbol;
        $this->symbol = $this->exchangeSymbol->symbol;
    }

    // Main handle method to process the cancellation of the order.
    public function handle()
    {
        try {
            // If the order does not have an exchange system ID, return early.
            if (blank($this->order->order_exchange_system_id)) {
                return;
            }

            // Fetch the current status of the order from the exchange.
            $result = $this->trader->withRESTApi()
                ->withLoggable($this->order)
                ->withOptions([
                    'symbol' => $this->symbol->token . 'USDT',
                    'orderId' => $this->order->order_exchange_system_id,
                ])->getOrder();

            // Check if the order has been partially or fully executed.
            if ($result['executedQty'] > 0) {
                // Cancel the entire position if any part of the order was executed.
                $this->cancelPosition();
            } else {
                // Cancel the limit order if it hasn't been executed.
                $this->cancelLimitOrder();
            }

            // Update the order status to 'cancelled'.
            $this->order->update(['status' => 'cancelled']);
            $this->jobPollerInstance->markAsComplete();
        } catch (\Throwable $e) {
            $this->jobPollerInstance->markAsError($e);
            // Throw a TryCatchException if an error occurs during cancellation.
            throw new TryCatchException(
                throwable: $e,
                additionalData: ['order_id' => $this->orderId]
            );
        }
    }

    // Cancels the entire position by creating a cancellation order.
    protected function cancelPosition()
    {
        // Create a new order with type 'POSITION-CANCELLATION'.
        $cancellationOrder = Order::create([
            'position_id' => $this->order->position->id,
            'status' => 'new',
            'type' => 'POSITION-CANCELLATION',
            'price_ratio_percentage' => 0,
            'amount_divider' => 1,
            'entry_quantity' => 0,
        ]);

        // Dispatch the cancellation order job for execution.
        DispatchOrderJob::dispatch($cancellationOrder->id);
    }

    // Cancels the limit order on the exchange using the trader's API.
    protected function cancelLimitOrder()
    {
        $this->trader
            ->withRESTApi()
            ->withLoggable($this->order)
            ->withOptions([
                'symbol' => $this->exchangeSymbol->symbol->token . 'USDT',
                'orderId' => $this->order->order_exchange_system_id,
            ])->cancelOrder();
    }
}
