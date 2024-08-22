<?php

namespace Nidavellir\Trading\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Nidavellir\Trading\Models\Position;

class PositionPolicy
{
    use HandlesAuthorization;

    public function viewAny(Position $position)
    {
        return true;
    }

    public function view(Position $position, Position $model)
    {
        return true;
    }

    public function create(Position $position)
    {
        return true;
    }

    public function update(Position $position, Position $model)
    {
        return true;
    }

    public function delete(Position $position, Position $model)
    {
        return $model->canBeDeleted();
    }

    public function restore(Position $position, Position $model)
    {
        return $model->trashed();
    }

    public function forceDelete(Position $position, Position $model)
    {
        return $model->trashed();
    }

    public function replicate(Position $position, Position $model)
    {
        return false;
    }
}
