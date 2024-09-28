<?php

namespace Nidavellir\Trading;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nidavellir\Trading\Models\JobQueue;

class JobPollerManager
{
    protected string $blockUUID;

    protected int $currentIndex = 1;

    protected array $jobs = [];

    protected int $maxParallelJobs;

    public function __construct()
    {
        // Initialize with a new block UUID but avoid logging here
        $this->blockUUID = (string) Str::uuid();

        // Set max parallel jobs from the nidavellir configuration file
        $this->maxParallelJobs = config('nidavellir.system.job_poller.max_parallel_jobs', 1);
    }

    /**
     * Add a job with dynamic arguments.
     *
     * @param  string  $className  The class name of the job
     * @param  mixed  ...$arguments  The arguments to pass to the job
     * @return $this
     */
    public function addJob(string $className, ...$arguments)
    {
        $this->jobs[] = [
            'class' => $className,
            'arguments' => $arguments,
        ];

        return $this;
    }

    /**
     * Regenerate or use a new block UUID
     *
     * @param  string|null  $blockUUID  Optional UUID to set, otherwise generates a new one
     * @return $this
     */
    public function newBlockUUID(?string $blockUUID = null)
    {
        $this->blockUUID = $blockUUID ?? (string) Str::uuid();

        return $this;
    }

    /**
     * Add all collected jobs to the database.
     *
     * @return \Illuminate\Support\Collection
     */
    public function release()
    {
        $createdJobs = collect();

        foreach ($this->jobs as $job) {
            $jobModel = JobQueue::create([
                'class' => $job['class'],
                'arguments' => json_encode($job['arguments']),
                'status' => 'pending',
                'block_uuid' => $this->blockUUID,
            ]);

            $createdJobs->push($jobModel);
        }

        $this->jobs = [];

        return $createdJobs;
    }

    /**
     * Use an existing block UUID
     *
     * @param  string  $blockUUID  The block UUID to use
     * @return $this
     */
    public function onBlockUUID(string $blockUUID)
    {
        $this->blockUUID = $blockUUID;

        return $this;
    }

    public function getEligibleJobs()
    {
        // Retrieve block UUIDs that have jobs in 'running' or 'failed' status
        $blockedUUIDs = JobQueue::whereIn('status', ['running', 'failed'])
            ->pluck('block_uuid')
            ->unique()
            ->filter()
            ->toArray();

        // Retrieve pending jobs excluding those from blocked UUIDs
        $pendingJobs = JobQueue::where('status', 'pending')
            ->whereNotIn('block_uuid', $blockedUUIDs) // Exclude blocked UUIDs
            ->orderBy('id', 'asc') // Use primary key 'id' for ordering
            ->limit($this->maxParallelJobs) // Limit to maxParallelJobs
            ->lockForUpdate()
            ->get();

        $eligibleJobs = collect();

        foreach ($pendingJobs as $job) {
            // Add the job to the collection if the block UUID is not blocked
            $eligibleJobs->push($job);

            // Stop if we have collected enough jobs for the max parallel jobs
            if ($eligibleJobs->count() >= $this->maxParallelJobs) {
                break;
            }
        }

        return $eligibleJobs;
    }

    public function handle()
    {
        $hostname = gethostname();

        $eligibleJobs = $this->getEligibleJobs();

        // If no eligible jobs, exit the method
        if ($eligibleJobs->isEmpty()) {
            return;
        }

        foreach ($eligibleJobs as $job) {
            DB::transaction(function () use (&$job, $hostname) {
                $lockedJob = JobQueue::lockForUpdate()->find($job->id);

                if ($lockedJob->status !== 'pending') {
                    return;
                }

                // Mark job as running and record the start time in milliseconds
                $lockedJob->update([
                    'status' => 'running',
                    'hostname' => $hostname,
                    'started_at' => now()->valueOf(), // Use valueOf() to store timestamp in milliseconds
                ]);

                // We update $job reference to the locked job with fresh data
                $job = $lockedJob;
            });

            try {
                // Instantiate the job class with arguments outside of the transaction
                $jobInstance = new $job->class(...json_decode($job->arguments, true));

                if (method_exists($jobInstance, 'setJobPollerInstance')) {
                    $jobInstance->setJobPollerInstance($job);
                }

                // Dispatch the job outside of the transaction; workers will pick it up
                dispatch($jobInstance)->onQueue($this->getQueueName());
            } catch (\Throwable $e) {
                // Update the job status to 'failed' outside the transaction
                $job->update(['status' => 'failed']);
            }
        }
    }

    /**
     * Get the queue name that this job poller should use.
     *
     * @return string
     */
    public function getQueueName()
    {
        return config('nidavellir.system.job_poller.queue_name', gethostname());
    }
}
