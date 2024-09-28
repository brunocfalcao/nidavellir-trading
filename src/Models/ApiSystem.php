<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

/**
 * @property string $name
 * @property string $canonical
 * @property string $namespace_class_rest
 * @property string $namespace_class_websocket
 * @property string $futures_url_rest_prefix
 * @property string $futures_url_websockets_prefix
 * @property string $generic_url_prefix
 */
class ApiSystem extends AbstractModel
{
    protected $casts = [
        'other_information' => 'array',
    ];

    public function symbols()
    {
        return $this->belongsToMany(
            Symbol::class,
            'exchange_symbols',
            'api_system_id',
            'symbol_id'
        );
    }

    public function apiRequestLogs()
    {
        return $this->morphMany(ApiRequestsLog::class, 'loggable');
    }

    public function exchangeSymbols()
    {
        return $this->hasMany(ExchangeSymbol::class);
    }
}
