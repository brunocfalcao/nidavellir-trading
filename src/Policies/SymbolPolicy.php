<?php

namespace Nidavellir\Trading\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Nidavellir\Trading\Models\Symbol;

class SymbolPolicy
{
    use HandlesAuthorization;

    public function viewAny(Symbol $symbol)
    {
        return true;
    }

    public function view(Symbol $symbol, Symbol $model)
    {
        return true;
    }

    public function create(Symbol $symbol)
    {
        return true;
    }

    public function update(Symbol $symbol, Symbol $model)
    {
        return true;
    }

    public function delete(Symbol $symbol, Symbol $model)
    {
        return $model->canBeDeleted();
    }

    public function restore(Symbol $symbol, Symbol $model)
    {
        return $model->trashed();
    }

    public function forceDelete(Symbol $symbol, Symbol $model)
    {
        return $model->trashed();
    }

    public function replicate(Symbol $symbol, Symbol $model)
    {
        return false;
    }
}
