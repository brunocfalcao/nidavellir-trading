<?php

namespace Nidavellir\Trading\Exceptions;

use Throwable;

class ApiCallException extends \Exception
{
    protected int $apiLogId;

    public function __construct($message, $apiLogId, $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->apiLogId = $apiLogId;
    }

    public function getApiLogId(): int
    {
        return $this->apiLogId;
    }

    public function context(): array
    {
        return [
            'api_log_id' => $this->getApiLogId(),
        ];
    }
}
