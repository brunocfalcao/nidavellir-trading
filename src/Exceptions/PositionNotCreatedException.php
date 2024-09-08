<?php

namespace Nidavellir\Exceptions;

use Exception;

class PositionNotCreatedException extends Exception
{
    protected $positionId;

    public function __construct($message, $positionId, $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->positionId = $positionId;
    }

    public function getPositionId()
    {
        return $this->positionId;
    }
}
