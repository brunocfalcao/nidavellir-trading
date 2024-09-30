<?php

namespace Nidavellir\Trading\Models;

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
}
