<?php

namespace Nidavellir\Trading;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        // Set max parallel jobs from the environment variable or default to 2
        $this->maxParallelJobs = (int) env('MAX_PARALLEL_JOBS', 5);
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

        Log::info('Job added: ' . class_basename($className));
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
        Log::info('New Block UUID generated or set: ' . $this->blockUUID);
        return $this;
    }

    /**
     * Add all collected jobs to the database.
     *
     * @return \Illuminate\Support\Collection
     */
    public function add()
    {
        $createdJobs = collect();

        foreach ($this->jobs as $job) {
            $jobModel = JobQueue::create([
                'class' => $job['class'],
                'arguments' => json_encode($job['arguments']),
                'status' => 'pending',
                'block_uuid' => $this->blockUUID,
            ]);

            Log::info('Job saved to the database: ' . class_basename($jobModel->class));
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
        Log::info('Using existing Block UUID: ' . $this->blockUUID);
        return $this;
    }

    /**
     * Get eligible jobs based on the max parallel jobs allowed.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getEligibleJobs()
    {
        // Retrieve only the required number of pending jobs using LIMIT
        $pendingJobs = JobQueue::where('status', 'pending')
            ->orderBy('id', 'asc') // Use primary key 'id' for ordering
            ->limit($this->maxParallelJobs) // Limit to maxParallelJobs
            ->lockForUpdate()
            ->get();

        $eligibleJobs = collect();

        foreach ($pendingJobs as $job) {
            Log::info('Assessing ' . class_basename($job->class));

            // Directly add the job to the collection
            $eligibleJobs->push($job);

            // Stop if we have collected enough jobs for the max parallel jobs
            if ($eligibleJobs->count() >= $this->maxParallelJobs) {
                break;
            }
        }

        Log::info('Eligible jobs fetched: ' . $eligibleJobs->count());
        return $eligibleJobs;
    }

    /**
     * Handle the job execution.
     */
    public function handle()
    {
        $hostname = gethostname();

        if ($this->maxParallelJobs > 0) {
            $eligibleJobs = $this->getEligibleJobs();

            // If no eligible jobs, exit the method
            if ($eligibleJobs->isEmpty()) {
                Log::info("No more eligible jobs to run.");
                return;
            }

            foreach ($eligibleJobs as $job) {
                Log::info("Job cycle start for: " . class_basename($job->class));

                DB::transaction(function () use ($job, $hostname) {
                    Log::info('Locking for update: ' . class_basename($job->class));
                    $lockedJob = JobQueue::lockForUpdate()->find($job->id);

                    if ($lockedJob->status !== 'pending') {
                        Log::warning("Job already in progress or completed: " . class_basename($lockedJob->class));
                        return;
                    }

                    // Mark job as running
                    $lockedJob->update([
                    'status' => 'running',
                    'hostname' => $hostname,
                    'started_at' => now(),
                    ]);

                    try {
                        $jobInstance = new $lockedJob->class(...json_decode($lockedJob->arguments, true));

                        if (method_exists($jobInstance, 'setJobPollerInstance')) {
                            $jobInstance->setJobPollerInstance($lockedJob);
                        }

                        Log::info("Job dispatching: " . class_basename($lockedJob->class));
                        dispatch($jobInstance)->onQueue($this->getQueueName());

                        Log::info("Job dispatched: " . class_basename($lockedJob->class));
                    } catch (\Throwable $e) {
                        Log::error("Failed to dispatch job: " . class_basename($lockedJob->class) . " - " . $e->getMessage());
                        $lockedJob->update(['status' => 'failed']);
                    }
                });

                // Since getEligibleJobs() ensures only the allowed number of jobs are fetched,
                // there's no need for further checks; the loop will naturally terminate after maxParallelJobs jobs
            }
        } else {
            Log::info("No available jobs to run in parallel.");
        }
    }

    /**
     * Get the queue name that this job poller should use.
     *
     * @return string
     */
    public function getQueueName()
    {
        return env('JOB_POLLER_QUEUE_NAME', gethostname());
    }
}
