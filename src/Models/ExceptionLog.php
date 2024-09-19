<?php

namespace Nidavellir\Trading\Models;

use Illuminate\Database\Eloquent\Model;

class ExceptionLog extends Model
{
    protected $table = 'exceptions_log';

    protected $fillable = [
        'message',
        'exception_message',
        'filename',
        'additional_data',
        'stack_trace',
    ];

    protected $casts = [
        'additional_data' => 'array',
        'stack_trace' => 'array',
    ];
}
