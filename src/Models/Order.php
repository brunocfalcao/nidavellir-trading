<?php

namespace Nidavellir\Trading\Models;

use Brunocfalcao\LaravelHelpers\Traits\ForModels\HasCustomQueryBuilder;
use Brunocfalcao\LaravelHelpers\Traits\ForModels\HasValidations;
use Nidavellir\Trading\Abstracts\AbstractModel;

/**
 * @property int $position_id
 * @property string $status
 * @property string $uuid
 * @property string $type
 * @property decimal $price_ratio_percentage
 * @property int $amount_divider
 * @property float $mark_price
 * @property string $system_order_id
 */
class Order extends AbstractModel
{
    use HasCustomQueryBuilder, HasValidations;

    protected $casts = [
        'api_result' => 'array',
    ];

    public $rules = [
        //'name' => ['required'],
    ];

    public function getRules()
    {
        return [
            //'canonical' => ['required'],
        ];
    }

    public function apiRequestLogs()
    {
        return $this->morphMany(ApiRequestsLog::class, 'loggable');
    }

    public function position()
    {
        return $this->belongsTo(Position::class);
    }
}
