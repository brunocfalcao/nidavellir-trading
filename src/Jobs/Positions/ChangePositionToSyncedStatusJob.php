<?php

namespace Nidavellir\Trading\Jobs\Positions;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Models\Position;

/**
 * ChangePositionToSyncedStatusJob updates the status of a
 * trading position to "synced" after all necessary operations
 * on the position have been successfully completed.
 *
 * - Updates the position status to 'synced'.
 * - If an error occurs, updates the status to 'error' and
 *   throws a custom exception.
 */
class ChangePositionToSyncedStatusJob extends AbstractJob
{
    // The ID of the position to update.
    public int $positionId;

    /**
     * Constructor to initialize the position ID.
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
            // Find the position by its ID and update its status to 'synced'.
            $position = Position::findOrFail($this->positionId);
            $position->update(['status' => 'synced']);
        } catch (\Throwable $e) {
            // If an error occurs, update the status to 'error', log it and throw a custom exception.
            $position->update(['status' => 'error']);

            throw new TryCatchException(
                throwable: $e,
                additionalData: [
                    'position_id' => $positionId]
            );
        }
    }
}
