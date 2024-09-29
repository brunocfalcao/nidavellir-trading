<?php

namespace Nidavellir\Trading\Models;

use Illuminate\Support\Facades\Log;
use Nidavellir\Trading\Abstracts\AbstractModel;

/**
 * Class: JobQueue
 *
 * This model represents the job queue where various jobs are managed
 * within the system. It provides methods to mark jobs as complete or
 * failed, attach related models, and manage job lifecycle information.
 *
 * Important points:
 * - Supports polymorphic relationships with other models.
 * - Manages job completion, failure status, and duration tracking.
 */
class JobQueue extends AbstractModel
{
    // Define the database table associated with this model.
    protected $table = 'job_queue';

    // Retrieve the parent related model for this job.
    public function related()
    {
        return $this->morphTo();
    }

    // Mark the job as completed.
    public function markAsComplete()
    {
        $this->finalizeJob('completed');
    }

    // Mark the job as failed, capturing the error message.
    public function markAsError(\Throwable $e)
    {
        $this->error_message = $e->getMessage();
        $this->finalizeJob('failed');
    }

    // Finalize the job by setting its status, completion time, and duration.
    private function finalizeJob(string $status)
    {
        // Record the completion time as a Unix timestamp in milliseconds.
        $this->completed_at = now()->valueOf();

        // Calculate duration as the difference between completed_at and started_at.
        $this->duration = $this->started_at ? $this->completed_at - $this->started_at : null;

        // Update the job status.
        $this->status = $status;

        // Save the changes to the database.
        $this->save();
    }

    // Attach a relatable model (polymorphic relationship) to this job queue entry.
    public function withRelatable($model)
    {
        // Ensure the provided model is a valid Eloquent model instance.
        if (!is_object($model) || !method_exists($model, 'getKey')) {
            throw new \InvalidArgumentException('Invalid relatable model.');
        }

        // Set the polymorphic relationship fields.
        $this->related_id = $model->getKey();
        $this->related_type = get_class($model);

        return $this; // Return the current instance to allow method chaining.
    }
}
