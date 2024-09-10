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
class ExceptionsLog extends AbstractModel
{
    protected $table = 'exceptions_log';

    protected $casts = [
        'attributes' => 'array',
    ];

    public function loggable()
    {
        return $this->morphTo();
    }
}
