<?php

namespace Nidavellir\Trading\Abstracts;

use Exception;
use Throwable;

abstract class AbstractException extends Exception
{
    protected $previous;

    protected $additionalData;

    public function __construct($message = '', ?Throwable $throwable = null, array $additionalData = [])
    {
        // If no message is provided, use the previous exception's message
        if (empty($message) && $throwable) {
            $message = $throwable->getMessage();
        }

        parent::__construct($message, 0, $throwable);
        $this->previous = $throwable;
        $this->additionalData = $additionalData;
    }

    public function getAdditionalData(): array
    {
        return $this->additionalData;
    }
}
