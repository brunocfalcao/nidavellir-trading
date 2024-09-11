<?php

namespace Nidavellir\Trading\Abstracts;

use Brunocfalcao\LaravelHelpers\Traits\ForModels\HasCustomQueryBuilder;
use Brunocfalcao\LaravelHelpers\Traits\ForModels\HasValidations;
use Illuminate\Database\Eloquent\Model;
use Nidavellir\Trading\Models\ExceptionsLog;

abstract class AbstractModel extends Model
{
    use HasCustomQueryBuilder, HasValidations;

    protected $guarded = [];

    public function canBeDeleted()
    {
        return true;
    }

    public function exceptionsLogs()
    {
        return $this->morphMany(ExceptionsLog::class, 'loggable');
    }
}
