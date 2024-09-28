<?php

namespace Nidavellir\Trading\Models;

use Illuminate\Database\Eloquent\Model;
use Nidavellir\Trading\Abstracts\AbstractModel;
use Illuminate\Support\Facades\Log;

class JobQueue extends AbstractModel
{
    protected $table = 'job_queue';

    /**
     * Mark this job as complete.
     *
     * @return void
     */
    public function markAsComplete(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        Log::info("Job marked as complete", [
            'job_id' => $this->id,
            'class' => $this->class,
            'full_class_name' => $this->class,
            'block_uuid' => $this->block_uuid
        ]);
    }

    /**
     * Mark this job as failed with an error message.
     *
     * @param \Throwable $e
     * @return void
     */
    public function markAsError(\Throwable $e): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $e->getMessage(),
            'completed_at' => now(),
        ]);

        Log::error("Job marked as failed", [
            'job_id' => $this->id,
            'class' => $this->class,
            'block_uuid' => $this->block_uuid,
            'error_message' => $e->getMessage()
        ]);
    }
}
