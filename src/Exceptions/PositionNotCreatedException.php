<?php

namespace Nidavellir\Exceptions;

use Exception;

class PositionNotCreatedException extends Exception
{
    protected int $positionId;

    public function __construct($message, $positionId, $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->positionId = $positionId;
    }

    // Return the position ID associated with the exception
    public function getPositionId(): int
    {
        return $this->positionId;
    }

    // Context for logging or reporting
    public function context(): array
    {
        return [
            'position_id' => $this->getPositionId(),
        ];
    }
}
