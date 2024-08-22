<?php

namespace Nidavellir\Trading\Observers;

use Nidavellir\Trading\Events\Orders\OrderCreatedEvent;
use Nidavellir\Trading\Models\Order;

class OrderObserver
{
    public function saving(Order $model)
    {
        //
    }

    public function updated(Order $model)
    {
        //
    }

    public function deleted(Order $model)
    {
        //
    }

    public function creating(Order $order)
    {
        $order->validate();
    }

    public function created(Order $model)
    {
        // Trigger order created event.
        OrderCreatedEvent::dispatch($model);
    }
}
