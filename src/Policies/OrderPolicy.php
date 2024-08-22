<?php

namespace Nidavellir\Trading\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Nidavellir\Trading\Models\Order;

class OrderPolicy
{
    use HandlesAuthorization;

    public function viewAny(Order $order)
    {
        return true;
    }

    public function view(Order $order, Order $model)
    {
        return true;
    }

    public function create(Order $order)
    {
        return true;
    }

    public function update(Order $order, Order $model)
    {
        return true;
    }

    public function delete(Order $order, Order $model)
    {
        return $model->canBeDeleted();
    }

    public function restore(Order $order, Order $model)
    {
        return $model->trashed();
    }

    public function forceDelete(Order $order, Order $model)
    {
        return $model->trashed();
    }

    public function replicate(Order $order, Order $model)
    {
        return false;
    }
}
