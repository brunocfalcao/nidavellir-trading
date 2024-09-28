<?php

namespace Nidavellir\Trading\Abstracts;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Models\JobQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

abstract class AbstractJob implements ShouldQueue
{
    use Batchable,
        Dispatchable,
        InteractsWithQueue,
        Queueable,
        SerializesModels;

    public $tries = 1;

    public $timeout = 180;

    public JobQueue $jobPollerInstance;

    // Define the backoff time in seconds
    public function backoff()
    {
        return [15];
    }

    // Define the retry time in seconds
    public function retryAfter()
    {
        return 1;
    }

    public function setJobPollerInstance($jobPollerInstance)
    {
        $this->jobPollerInstance = $jobPollerInstance;
    }
}
