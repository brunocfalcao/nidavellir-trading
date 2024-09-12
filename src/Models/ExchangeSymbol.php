<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

/**
 * @property int $coinmarketcap_id
 * @property string $name
 * @property string $token
 * @property string $website
 * @property int $rank
 * @property string $description
 * @property string $image_url
 */
class ExchangeSymbol extends AbstractModel
{
    protected $casts = [
        'api_symbol_information' => 'array',
        'api_notional_and_leverage_symbol_information' => 'array',
    ];

    public function symbol()
    {
        return $this->belongsTo(Symbol::class);
    }

    public function exchange()
    {
        return $this->belongsTo(Exchange::class);
    }
}
