<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

/**
 * @property int $coinmarketcap_id
 * @property string $name
 * @property string $token
 * @property string $website
 * @property string $rank
 * @property string $description
 * @property string $image_url
 */
class Symbol extends AbstractModel
{
    protected $casts = [
        'is_active' => 'boolean',
        'indicator_last_synced_at' => 'datetime',
    ];

    public function apiRequestLogs()
    {
        return $this->morphMany(ApiRequestsLog::class, 'loggable');
    }

    public function exchanges()
    {
        return $this->belongsToMany(
            ApiSystem::class,
            'exchange_symbols',
            'symbol_id',
            'api_system_id'
        );
    }

    // Returns the specific exchange-symbol with more symbol data.
    public function exchangeSymbols()
    {
        return $this->hasMany(ExchangeSymbol::class);
    }
}
