<?php

namespace Nidavellir\Trading\Exceptions;

use Throwable;

class OrderNotCreatedException extends \Exception
{
    protected int $orderId;

    public function __construct($message, $orderId, $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->orderId = $orderId;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function context(): array
    {
        return [
            'order_id' => $this->getOrderId(),
        ];
    }
}
