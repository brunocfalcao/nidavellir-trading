<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

/**
 * @property string $caller_name
 * @property int $exchange_id
 * @property int $trader_id
 * @property int $exchange_id
 * @property int $exchange_symbol_id
 * @property int $order_id
 * @property array $mapper_properties
 */
class ApiLog extends AbstractModel
{
    protected $casts = [
        'mapper_properties' => 'array',
    ];

    public function exceptionLogs()
    {
        return $this->morphMany(ExceptionsLog::class, 'loggable');
    }

    public function exchange()
    {
        return $this->belongsTo(Exchange::class);
    }
}
