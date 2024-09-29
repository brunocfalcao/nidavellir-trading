<?php

namespace Nidavellir\Trading\Abstracts;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Models\JobQueue;

abstract class AbstractJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public JobQueue $jobPollerInstance;

    public function backoff()
    {
        return [15];
    }

    public function retryAfter()
    {
        return 1;
    }

    public function setJobPollerInstance(JobQueue $jobPollerInstance)
    {
        $this->jobPollerInstance = $jobPollerInstance;
    }
}
