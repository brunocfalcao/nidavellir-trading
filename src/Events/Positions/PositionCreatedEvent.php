<?php

namespace Nidavellir\Trading\Events\Positions;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Models\Position;

class PositionCreatedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Position $position;

    public function __construct(Position $position)
    {
        $this->position = $position;
    }
}
