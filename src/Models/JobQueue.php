<?php

namespace Nidavellir\Trading\Models;

use Illuminate\Support\Facades\Log;
use Nidavellir\Trading\Abstracts\AbstractModel;

class JobQueue extends AbstractModel
{
    protected $table = 'job_queue';

    /**
     * Mark the job as complete.
     */
    public function markAsComplete()
    {
        $this->finalizeJob('completed');
    }

    /**
     * Mark the job as failed.
     */
    public function markAsError(\Throwable $e)
    {
        $this->error_message = $e->getMessage();
        $this->finalizeJob('failed');
    }

    /**
     * Finalize the job by setting completion time, duration, and status.
     */
    private function finalizeJob(string $status)
    {
        // Record the completion time as a Unix timestamp in milliseconds
        $this->completed_at = now()->valueOf();

        // Calculate duration as the difference between completed_at and started_at
        $this->duration = $this->started_at ? $this->completed_at - $this->started_at : null;

        $this->status = $status;
        $this->save();
    }
}
