<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

/**
 * @property string $name
 * @property string $canonical
 * @property string $full_qualified_class_name_rest
 * @property string $full_qualified_class_name_websocket
 * @property string $futures_url_rest_prefix
 * @property string $futures_url_websockets_prefix
 * @property string $generic_url_prefix
 */
class Exchange extends AbstractModel
{
    public function ipRequestWeights()
    {
        return $this->hasMany(IpRequestWeight::class);
    }

    public function endpointWeights()
    {
        return $this->hasMany(EndpointWeight::class);
    }

    public function symbols()
    {
        return $this->belongsToMany(Symbol::class);
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
