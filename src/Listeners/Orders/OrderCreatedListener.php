<?php

namespace Nidavellir\Trading\Listeners\Orders;

use Nidavellir\Trading\Abstracts\AbstractListener;
use Nidavellir\Trading\Events\Orders\OrderCreatedEvent;

class OrderCreatedListener extends AbstractListener
{
    public function handle(OrderCreatedEvent $event)
    {
        $order = $event->order;
    }
}
