<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

/**
 * @property string $exception_type
 * @property string $message
 * @property string $context
 * @property string $loggable_type
 * @property int $loggable_id
 */
class ExceptionLog extends AbstractModel
{
    public function loggable()
    {
        return $this->morphTo();
    }
}
