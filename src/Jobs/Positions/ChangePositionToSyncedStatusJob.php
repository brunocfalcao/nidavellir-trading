<?php

namespace Nidavellir\Trading\Jobs\Positions;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\PositionNotSyncedException;
use Nidavellir\Trading\Models\Position;
use Throwable;

/**
 * Class: ChangePositionToSyncedStatusJob
 *
 * This class updates the status of a trading position to "synced"
 * after all the necessary operations on the position have been
 * successfully completed.
 *
 * Important points:
 * - Updates the position status to 'synced'.
 * - If an error occurs, updates the status to 'error' and
 *   throws a custom exception.
 */
class ChangePositionToSyncedStatusJob extends AbstractJob
{
    /**
     * @var int The ID of the position to update.
     */
    public int $positionId;

    /**
     * Constructor to initialize the position ID.
     *
     * @param  int  $positionId  The ID of the position to update.
     */
    public function __construct(int $positionId)
    {
        $this->positionId = $positionId;
    }

    /**
     * Handle the job to update the position status.
     */
    public function handle()
    {
        try {
            /**
             * Find the position by its ID and update its
             * status to 'synced'.
             */
            Position::find($this->positionId)
                ->update(['status' => 'synced']);
        } catch (Throwable $e) {
            /**
             * If an error occurs, update the status to 'error'
             * and throw a custom exception with relevant details.
             */
            $position->update(['status' => 'error']);
            throw new PositionNotSyncedException(
                $e,
                $position
            );
        }
    }
}
