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
    public function symbols()
    {
        return $this->belongsToMany(Symbol::class);
    }

    // Returns the specific exchange-symbol with more symbol data.
    public function exchangeSymbols()
    {
        return $this->hasMany(ExchangeSymbol::class);
    }
}
