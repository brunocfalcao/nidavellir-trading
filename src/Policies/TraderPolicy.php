<?php

namespace Nidavellir\Trading\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Nidavellir\Trading\Models\Trader;

class TraderPolicy
{
    use HandlesAuthorization;

    public function viewAny(Trader $trader)
    {
        return true;
    }

    public function view(Trader $trader, Trader $model)
    {
        return true;
    }

    public function create(Trader $trader)
    {
        return true;
    }

    public function update(Trader $trader, Trader $model)
    {
        return true;
    }

    public function delete(Trader $trader, Trader $model)
    {
        return $model->canBeDeleted();
    }

    public function restore(Trader $trader, Trader $model)
    {
        return $model->trashed();
    }

    public function forceDelete(Trader $trader, Trader $model)
    {
        return $model->trashed();
    }

    public function replicate(Trader $trader, Trader $model)
    {
        return false;
    }
}
