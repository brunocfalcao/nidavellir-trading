<?php

namespace Nidavellir\Trading\Abstracts;

use Brunocfalcao\LaravelHelpers\Traits\ForModels\HasCustomQueryBuilder;
use Brunocfalcao\LaravelHelpers\Traits\ForModels\HasValidations;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractModel extends Model
{
    use HasCustomQueryBuilder, HasValidations;

    protected $guarded = [];

    public function canBeDeleted()
    {
        return true;
    }
}
