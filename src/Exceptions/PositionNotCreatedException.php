<?php

namespace Nidavellir\Trading\Exceptions;

use App\Exceptions\AbstractException;

/**
 * Usage:
 * throw new PositionNotCreatedException('Position not created', [
 * 'position' => $position])
 */
class PositionNotCreatedException extends AbstractException {}
