<?php

namespace Nidavellir\Trading\Models;

use Illuminate\Database\Eloquent\Model;

class ApiRequestLog extends Model
{
    protected $table = 'api_requests_log';

    protected $fillable = [
        'path',
        'payload',
        'http_method',
        'http_headers_sent',
        'http_response_code',
        'response',
        'http_headers_returned',
        'hostname',
    ];

    protected $casts = [
        'payload' => 'array',
        'http_headers_sent' => 'array',
        'response' => 'array',
        'http_headers_returned' => 'array',
    ];

    // Define the polymorphic relationship
    public function loggable()
    {
        return $this->morphTo();
    }
}
