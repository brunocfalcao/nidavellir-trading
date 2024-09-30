<?php

namespace Nidavellir\Trading\Jobs\Positions;

use Illuminate\Support\Facades\Bus;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\PositionValidationException;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Jobs\Orders\CancelOrderJob;
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

    // Main handle method to validate the position's status and orders.
    public function handle()
    {
        try {
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
                $syncedJobsToCancel = [];

                // Collect all orders with 'synced' status to be canceled.
                foreach ($this->position->orders->where('status', 'synced') as $order) {
                    $syncedJobsToCancel[] = new CancelOrderJob($order->id);
                }

                // Dispatch a batch job to cancel all synced orders.
                Bus::batch($syncedJobsToCancel)->dispatch();

                // Update the position status to 'complete-error'.
                $this->position->update(['status' => 'complete-error']);
            } else {
                // If no errors in orders, mark the position as 'synced'.
                $this->position->update(['status' => 'synced']);
            }
        } catch (\Throwable $e) {
            // If an exception occurs, update the position status to 'error'.
            $this->position->update(['status' => 'error']);

            // Throw a TryCatchException with additional data.
            throw new TryCatchException(
                throwable: $e,
                additionalData: ['position_id' => $this->positionId]
            );
        }
    }
}
