<?php

namespace Nidavellir\Trading\Exceptions;

use Nidavellir\Trading\Abstracts\AbstractException;

/**
 * Usage:
 * throw new PositionNotSyncedException('Position not created', [
 * 'position' => $position])
 */
class PositionNotSyncedException extends AbstractException
{
}
