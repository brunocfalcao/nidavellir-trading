<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

/**
 * @property int $trader_id
 * @property int $exchange_symbol_id
 * @property string $status
 * @property string $side
 * @property float $initial_mark_price
 * @property array $trade_configuration
 * @property float $total_trade_amount
 * @property int $leverage
 * @property string $comments
 * @property object $trader
 */
class Position extends AbstractModel
{
    protected $casts = [
        'trade_configuration' => 'array',
    ];

    public function trader()
    {
        return $this->belongsTo(Trader::class);
    }

    public function exchangeSymbol()
    {
        return $this->belongsTo(ExchangeSymbol::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
