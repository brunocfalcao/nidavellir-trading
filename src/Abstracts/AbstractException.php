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

        $this->additionalData = $additionalData;
        $this->previous = $throwable;

        parent::__construct($message, 0, $throwable);
    }

    public function getAdditionalData(): array
    {
        return $this->additionalData;
    }
}
