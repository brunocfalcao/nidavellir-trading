<?php

namespace Nidavellir\Trading\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;

class ExchangeSymbolPolicy
{
    use HandlesAuthorization;

    public function viewAny(ExchangeSymbol $exchangeSymbol)
    {
        return true;
    }

    public function view(ExchangeSymbol $exchangeSymbol, ExchangeSymbol $model)
    {
        return true;
    }

    public function create(ExchangeSymbol $exchangeSymbol)
    {
        return true;
    }

    public function update(ExchangeSymbol $exchangeSymbol, ExchangeSymbol $model)
    {
        return true;
    }

    public function delete(ExchangeSymbol $exchangeSymbol, ExchangeSymbol $model)
    {
        return $model->canBeDeleted();
    }

    public function restore(ExchangeSymbol $exchangeSymbol, ExchangeSymbol $model)
    {
        return $model->trashed();
    }

    public function forceDelete(ExchangeSymbol $exchangeSymbol, ExchangeSymbol $model)
    {
        return $model->trashed();
    }

    public function replicate(ExchangeSymbol $exchangeSymbol, ExchangeSymbol $model)
    {
        return false;
    }
}
