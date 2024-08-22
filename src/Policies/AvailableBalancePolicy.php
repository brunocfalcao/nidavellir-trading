<?php

namespace Nidavellir\Trading\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Nidavellir\Trading\Models\AvailableBalance;

class AvailableBalancePolicy
{
    use HandlesAuthorization;

    public function viewAny(AvailableBalance $availableBalance)
    {
        return true;
    }

    public function view(AvailableBalance $availableBalance, AvailableBalance $model)
    {
        return true;
    }

    public function create(AvailableBalance $availableBalance)
    {
        return true;
    }

    public function update(AvailableBalance $availableBalance, AvailableBalance $model)
    {
        return true;
    }

    public function delete(AvailableBalance $availableBalance, AvailableBalance $model)
    {
        return $model->canBeDeleted();
    }

    public function restore(AvailableBalance $availableBalance, AvailableBalance $model)
    {
        return $model->trashed();
    }

    public function forceDelete(AvailableBalance $availableBalance, AvailableBalance $model)
    {
        return $model->trashed();
    }

    public function replicate(AvailableBalance $availableBalance, AvailableBalance $model)
    {
        return false;
    }
}
