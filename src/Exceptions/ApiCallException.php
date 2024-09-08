<?php

namespace Nidavellir\Exceptions;

use Exception;

class ApiCallException extends Exception
{
    protected int $apiLogId;

    public function __construct($message, $apiLogId, $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->apiLogId = $apiLogId;
    }

    // Return the apiLog ID associated with the exception
    public function getApiLogId(): int
    {
        return $this->apiLogId;
    }

    // Context for logging or reporting
    public function context(): array
    {
        return [
            'api_log_id' => $this->getApiLogId(),
        ];
    }
}
