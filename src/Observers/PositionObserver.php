<?php

namespace Nidavellir\Trading\Observers;

use Nidavellir\Trading\Events\Positions\PositionCreatedEvent;
use Nidavellir\Trading\Models\Position;

class PositionObserver
{
    public function saving(Position $model)
    {
        //
    }

    public function updated(Position $model)
    {
        //
    }

    public function deleted(Position $model)
    {
        //
    }

    public function created(Position $model)
    {
        /**
         * When a new position is created, we will need to
         * trigger the respective orders logic. For that
         * we call the PositionCreatedEvent().
         */
        PositionCreatedEvent::dispatch($model);
    }
}
