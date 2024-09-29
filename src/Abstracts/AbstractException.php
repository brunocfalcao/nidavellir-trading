<?php

namespace Nidavellir\Trading\Abstracts;

use Exception;
use Throwable;

abstract class AbstractException extends Exception
{
    protected $previous;
    protected array $additionalData;

    public function __construct(string $message = '', ?Throwable $throwable = null, array $additionalData = [])
    {
        if (empty($message) && $throwable) {
            $message = $throwable->getMessage();
        }

        $this->additionalData = $additionalData;
        $this->previous = $throwable;

        parent::__construct($message, 0, $throwable);
    }

    public function getAdditionalData()
    {
        return $this->additionalData;
    }
}
