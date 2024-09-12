<?php

namespace Nidavellir\Trading\Exceptions;

use Nidavellir\Trading\Abstracts\AbstractException;
use Nidavellir\Trading\Models\Position;
use Throwable;

class PositionNotSyncedException extends AbstractException
{
    public function __construct(
        Throwable $originalException,
        ?Position $position = null,
        array $additionalData = []
    ) {
        // Automatically update the status of the position to 'error'
        if ($position) {
            $position->update(['status' => 'error']);
        }

        // Call the parent constructor with the original exception and other parameters
        parent::__construct($originalException, $position, $additionalData);
    }
}
