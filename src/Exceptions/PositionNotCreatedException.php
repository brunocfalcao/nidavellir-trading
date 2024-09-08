<?php

namespace Nidavellir\Trading\Exceptions;

use Throwable;

class PositionNotCreatedException extends \Exception
{
    protected int $positionId;

    public function __construct($message, $positionId, $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->positionId = $positionId;
    }

    public function getPositionId(): int
    {
        return $this->positionId;
    }

    public function context(): array
    {
        return [
            'position_id' => $this->getPositionId(),
        ];
    }
}
