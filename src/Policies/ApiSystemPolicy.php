<?php

namespace Nidavellir\Trading\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Nidavellir\Trading\Models\ApiSystem;

class ApiSystemPolicy
{
    use HandlesAuthorization;

    public function viewAny(ApiSystem $apiSystem)
    {
        return true;
    }

    public function view(ApiSystem $apiSystem, ApiSystem $model)
    {
        return true;
    }

    public function create(ApiSystem $apiSystem)
    {
        return true;
    }

    public function update(ApiSystem $apiSystem, ApiSystem $model)
    {
        return true;
    }

    public function delete(ApiSystem $apiSystem, ApiSystem $model)
    {
        return $model->canBeDeleted();
    }

    public function restore(ApiSystem $apiSystem, ApiSystem $model)
    {
        return $model->trashed();
    }

    public function forceDelete(ApiSystem $apiSystem, ApiSystem $model)
    {
        return $model->trashed();
    }

    public function replicate(ApiSystem $apiSystem, ApiSystem $model)
    {
        return false;
    }
}
