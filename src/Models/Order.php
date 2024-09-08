<?php

namespace Nidavellir\Trading\Models;

use Brunocfalcao\LaravelHelpers\Traits\ForModels\HasCustomQueryBuilder;
use Brunocfalcao\LaravelHelpers\Traits\ForModels\HasValidations;
use Nidavellir\Trading\Abstracts\AbstractModel;

class Order extends AbstractModel
{
    use HasCustomQueryBuilder, HasValidations;

    public $rules = [
        //'name' => ['required'],
    ];

    public function getRules()
    {
        return [
            //'canonical' => ['required'],
        ];
    }

    public function exceptionLogs()
    {
        return $this->morphMany(ExceptionLogger::class, 'loggable');
    }

    public function position()
    {
        return $this->belongsTo(Position::class);
    }
}
