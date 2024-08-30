<?php

namespace Nidavellir\Trading\Observers;

use Illuminate\Support\Str;
use Nidavellir\Trading\Events\Orders\OrderCreatedEvent;
use Nidavellir\Trading\Models\Order;

class OrderObserver
{
    public function saving(Order $model)
    {
        if (! $model->uuid) {
            $model->uuid = (string) Str::uuid();
        }
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
