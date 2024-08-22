<?php

namespace Nidavellir\Trading\Listeners\Orders;

use Nidavellir\Trading\Abstracts\AbstractListener;
use Nidavellir\Trading\Events\Orders\OrderCreatedEvent;

class OrderCreatedListener extends AbstractListener
{
    public function handle(OrderCreatedEvent $event)
    {
        /**
         * Time to create an order on the respective trade active
         * exchange. In a DCA bot, we will open LONG LIMIT BUY
         * and LONG LIMIT SELL orders. The token symbol, price
         * and quantity are mandatory. The logic for the
         * laddered entries are not part of the order
         * placement logic. The position id is also
         * mandatory.
         *
         * 1. Order is open with the current data.
         * 2.
         */
        dd('here');
    }
}
