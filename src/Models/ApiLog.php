<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

class ApiLog extends AbstractModel
{
    protected $casts = [
        'mapper_properties' => 'array',
    ];

    public function exceptionLogs()
    {
        return $this->morphMany(ExceptionLogger::class, 'loggable');
    }

    public function exchange()
    {
        return $this->belongsTo(Exchange::class);
    }
}
