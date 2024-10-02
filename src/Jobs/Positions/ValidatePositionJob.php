<?php

namespace Nidavellir\Trading\Jobs\Positions;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\PositionValidationException;
use Nidavellir\Trading\Models\Position;

/**
 * Class: ValidatePositionJob
 *
 * This job handles the validation of a trading position.
 * It checks for position existence, validates its status,
 * and manages any errors by cancelling associated orders.
 *
 * Important points:
 * - Ensures the position is valid before further processing.
 * - Cancels orders if the position has encountered an error.
 * - Updates the position's status accordingly.
 */
class ValidatePositionJob extends AbstractJob
{
    // ID of the position being validated.
    public int $positionId;

    // The position model instance being validated.
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->positionId = $positionId;
        $this->position = Position::find($positionId);
    }

    // Main method that processes the job's logic inherited from AbstractJob.
    protected function compute()
    {
        // Attach the position model to jobQueueEntry for better tracking.
        $this->attachRelatedModel($this->position);

        // Check if the position exists; throw an exception if not found.
        if (! $this->position) {
            throw new PositionValidationException(
                message: 'Position ID for validation not found',
                additionalData: ['position_id' => $this->positionId]
            );
        }

        // If the position is already synced or closed, exit the method.
        if (in_array($this->position->status, ['synced', 'closed'])) {
            return;
        }

        // Check if any orders associated with the position have errors.
        if ($this->position->orders->contains('status', 'error')) {
            // Rollback position.
            RollbackPositionJob::dispatch($this->position->id);
        } else {
            $this->position->update([
                'status' => 'synced',
                'started_at' => now(),
            ]);
        }
    }
}
