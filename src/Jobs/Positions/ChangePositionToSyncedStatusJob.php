<?php

namespace Nidavellir\Trading\Jobs\Positions;

use Illuminate\Support\Str;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\NidavellirException;
use Throwable;

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

    private $logBlock;

    /**
     * Constructor to initialize the position ID.
     */
    public function __construct(int $positionId)
    {
        $this->positionId = $positionId;
        $this->logBlock = Str::uuid();
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
        } catch (Throwable $e) {
            // If an error occurs, update the status to 'error', log it and throw a custom exception.
            $position->update(['status' => 'error']);

            throw new NidavellirException(
                originalException: $e,
                title: 'Error updating position status to synced for position ID: '.$this->positionId,
                loggable: $position
            );
        }
    }
}
