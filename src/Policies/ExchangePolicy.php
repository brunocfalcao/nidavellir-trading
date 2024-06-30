<?php

namespace Nidavellir\Trading\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Nidavellir\Trading\Models\Exchange;

class ExchangePolicy
{
    use HandlesAuthorization;

    public function viewAny(Exchange $exchange)
    {
        return true;
    }

    public function view(Exchange $exchange, Exchange $model)
    {
        return true;
    }

    public function create(Exchange $exchange)
    {
        return true;
    }

    public function update(Exchange $exchange, Exchange $model)
    {
        return true;
    }

    public function delete(Exchange $exchange, Exchange $model)
    {
        return $model->canBeDeleted();
    }

    public function restore(Exchange $exchange, Exchange $model)
    {
        return $model->trashed();
    }

    public function forceDelete(Exchange $exchange, Exchange $model)
    {
        return $model->trashed();
    }

    public function replicate(Exchange $exchange, Exchange $model)
    {
        return false;
    }
}
