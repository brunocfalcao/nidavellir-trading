<?php

namespace Nidavellir\Trading\Jobs\Positions;

use Illuminate\Support\Facades\Bus;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\PositionValidationException;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Jobs\Orders\CancelOrderJob;
use Nidavellir\Trading\Models\Position;

/**
 * This is the position validation after all the orders were
 * attempted to be placed. This will verify if there is an order with
 * error. If that's the case, then it will cancel all the orders
 * that were synced correctly and will put this position status
 * as complete-error.
 */
class ValidatePositionJob extends AbstractJob
{
    public int $positionId;

    public Position $position;

    /**
     * Constructor to initialize the position ID.
     */
    public function __construct(int $positionId)
    {
        $this->positionId = $positionId;

        $this->position = Position::find($positionId);
    }

    /**
     * Handle the job to update the position status.
     */
    public function handle()
    {
        try {
            if (! $this->position) {
                throw new PositionValidationException(
                    message: 'Position ID for validation not found',
                    additionalData: ['position_id' => $this->positionId]
                );
            }

            /**
             * Position already in synced or closed state? Return.
             */
            if (in_array($this->position->status, ['synced', 'closed'])) {
                return;
            }

            /**
             * Verify if there are errors with status = error.
             * If so, cancel the order.
             */
            if ($this->position->orders->contains('status', 'error')) {
                $syncedJobsToCancel = [];

                // Cancel all the orders that were already synced.
                foreach ($this->position->orders->where('status', 'synced') as $order) {
                    $syncedJobsToCancel[] = new CancelOrderJob($order->id);
                }

                /**
                 * Later we can check if we send a notification to the
                 * nidavellir admin, or to the trader.
                 */
                $batch = Bus::batch($syncedJobsToCancel)->dispatch();

                // Update position to inform that was an error, but all good.
                $this->position->update(['status' => 'complete-error']);
            } else {
                // All good. Position status can be changed to synced.
                $this->position->update(['status' => 'synced']);
            }
        } catch (\Throwable $e) {
            // If an error occurs, update the status to 'error', log it and throw a custom exception.
            $this->position->update(['status' => 'error']);

            throw new TryCatchException(
                throwable: $e,
                additionalData: [
                    'position_id' => $this->positionId]
            );
        }
    }
}
