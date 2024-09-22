<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

class ApiRequestLog extends AbstractModel
{
    protected $table = 'api_requests_log';

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
