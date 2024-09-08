<?php

namespace Nidavellir\Exceptions;

use Exception;

class MarketOrderNotCreatedException extends Exception
{
    protected int $orderId;

    public function __construct($message, $orderId, $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->orderId = $orderId;
    }

    // Return the order ID associated with the exception
    public function getOrderId(): int
    {
        return $this->orderId;
    }

    // Context for logging or reporting
    public function context(): array
    {
        return [
            'order_id' => $this->getOrderId(),
        ];
    }
}
