<?php

namespace Nidavellir\Trading\Exceptions;

use Nidavellir\Trading\Abstracts\AbstractException;
use Nidavellir\Trading\Models\Order;
use Throwable;

class OrderNotSyncedException extends AbstractException
{
    public function __construct(
        Throwable $originalException,
        ?Order $order = null,
        array $additionalData = []
    ) {
        // Automatically update the status of the position to 'error'
        if ($order) {
            $order->update(['status' => 'error']);
        }

        // Call the parent constructor with the original exception and other parameters
        parent::__construct($originalException, $order, $additionalData);
    }
}
