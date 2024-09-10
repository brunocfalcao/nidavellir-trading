<?php

namespace Nidavellir\Trading\Exceptions;

use Nidavellir\Trading\Abstracts\AbstractException;

/**
 * Usage:
 * throw new PositionNotCreatedException('Position not created', [
 * 'position' => $position])
 */
class PositionNotCreatedException extends AbstractException {}
