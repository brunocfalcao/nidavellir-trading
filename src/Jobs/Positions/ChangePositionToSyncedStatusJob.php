<?php

namespace Nidavellir\Trading\Jobs\Positions;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\PositionNotSyncedException;
use Nidavellir\Trading\Models\Position;
use Throwable;

class ChangePositionToSyncedStatusJob extends AbstractJob
{
    public int $positionId;

    public function __construct(int $positionId)
    {
        $this->positionId = $positionId;
    }

    public function handle()
    {
        try {
            Position::find($this->positionId)
                ->update(['status' => 'synced']);
        } catch (Throwable $e) {
            $position->update(['status' => 'error']);
            throw new PositionNotSyncedException(
                $e->getMessage(),
                ['position_id' => $this->positionId],
                $position
            );
        }
    }
}
