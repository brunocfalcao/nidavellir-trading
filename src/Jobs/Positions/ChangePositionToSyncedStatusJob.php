<?php

namespace Nidavellir\Trading\Jobs\Positions;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Models\ApplicationLog;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\NidavellirException;
use Illuminate\Support\Str;
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
            // Log the start of the job
            ApplicationLog::withActionCanonical('Position.SyncStatus.Start')
                ->withDescription('Starting job to update position status to synced')
                ->withPositionId($this->positionId)
                ->withBlock($this->logBlock)
                ->saveLog();

            // Find the position by its ID and update its status to 'synced'.
            $position = Position::findOrFail($this->positionId);
            $position->update(['status' => 'synced']);

            // Log the successful update
            ApplicationLog::withActionCanonical('Position.SyncStatus.Success')
                ->withDescription('Position status updated to synced successfully')
                ->withLoggable($position)
                ->withBlock($this->logBlock)
                ->saveLog();
        } catch (Throwable $e) {
            // If an error occurs, update the status to 'error', log it and throw a custom exception.
            $position->update(['status' => 'error']);

            ApplicationLog::withActionCanonical('Position.SyncStatus.Error')
                ->withDescription('Error occurred while updating position status to synced')
                ->withReturnData(['error' => $e->getMessage()])
                ->withLoggable($position)
                ->withBlock($this->logBlock)
                ->saveLog();

            throw new NidavellirException(
                originalException: $e,
                title: 'Error updating position status to synced for position ID: '.$this->positionId,
                loggable: $position
            );
        }
    }
}
