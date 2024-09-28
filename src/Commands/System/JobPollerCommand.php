<?php

namespace Nidavellir\Trading\Commands\System;

use Illuminate\Console\Command;
use Nidavellir\Trading\Services\JobPollerManager;

class JobPollerCommand extends Command
{
    protected $signature = 'jobs:poller';

    protected $description = 'Polls and selects jobs for execution from the jobs_queue table';

    public function handle()
    {
        $jobPollerManager = new JobPollerManager;
        $jobPollerManager->handle(); // Delegate the handling to the JobPollerManager
    }
}
