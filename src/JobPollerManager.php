<?php

namespace Nidavellir\Trading;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nidavellir\Trading\Models\JobQueue;

/**
 * Class JobPollerManager
 *
 * This class manages the lifecycle of job polling,
 * grouping, releasing, and dispatching jobs. It
 * provides mechanisms to handle job creation,
 * release, and management of the execution process,
 * ensuring that jobs are run in parallel according
 * to configuration limits.
 */
class JobPollerManager
{
    // UUID for identifying a block of jobs processed together.
    protected string $blockUUID;

    // Index for tracking the current job within a block.
    protected int $currentIndex = 1;

    // Array holding all jobs added to this manager instance.
    protected array $jobs = [];

    // Maximum number of parallel jobs allowed, as configured.
    protected int $maxParallelJobs;

    // Relatable model instance for polymorphic relationship.
    protected $relatableModel = null;

    public function __construct()
    {
        $this->blockUUID = (string) Str::uuid();
        $this->maxParallelJobs = config(
            'nidavellir.system.job_poller.max_parallel_jobs',
            1
        );
    }

    /**
     * Adds multiple jobs to the internal list at once.
     * If a relatable model is set, it applies to all jobs.
     */
    public function addJobs(array $jobs)
    {
        foreach ($jobs as $job) {
            // Ensure that the job instance is a valid object with the expected class.
            if (is_object($job)) {
                $this->jobs[] = [
                    'class' => get_class($job),
                    'arguments' => get_object_vars($job), // Store as array.
                    'related_id' => $this->relatableModel ? $this->relatableModel->getKey() : null,
                    'related_type' => $this->relatableModel ? get_class($this->relatableModel) : null,
                ];
            }
        }

        // Clear relatable model after adding jobs.
        $this->relatableModel = null;

        return $this;
    }

    /**
     * Adds a new job to the internal list of jobs to be processed.
     * This method prepares a job with the specified class name
     * and its associated arguments, storing it for later release.
     */
    public function addJob(string $className, ...$arguments)
    {
        // Store job details including its class name and arguments.
        $this->jobs[] = [
            'class' => $className,
            'arguments' => $arguments,
            'related_id' => $this->relatableModel ? $this->relatableModel->getKey() : null,
            'related_type' => $this->relatableModel ? get_class($this->relatableModel) : null,
        ];

        // Clear relatable model after adding the job.
        $this->relatableModel = null;

        return $this;
    }

    /**
     * Sets a new block UUID to group jobs under a common identifier.
     * If a UUID is provided, it uses that; otherwise, it generates a
     * new one. This helps in managing the processing of related jobs.
     */
    public function newBlockUUID(?string $blockUUID = null)
    {
        // Assign the provided UUID or generate a new one if null.
        $this->blockUUID = $blockUUID ?? (string) Str::uuid();

        return $this;
    }

    /**
     * Sets a relatable model that will be associated with the next job(s).
     * Allows attaching a polymorphic relationship to the job entries.
     */
    public function withRelatable($model)
    {
        $this->relatableModel = $model;

        return $this;
    }

    /**
     * Releases all the added jobs into the system's job queue.
     * This method persists each job into the database with a
     * 'pending' status and associates them with the current block UUID.
     */
    public function release()
    {
        // Initialize a collection to hold created job records.
        $createdJobs = collect();

        foreach ($this->jobs as $job) {
            // Create each job entry in the database with the 'pending' status.
            $jobModel = JobQueue::create([
                'class' => $job['class'],
                'arguments' => json_encode($job['arguments']),
                'status' => 'pending',
                'block_uuid' => $this->blockUUID,
                'related_id' => $job['related_id'],
                'related_type' => $job['related_type'],
            ]);
            $createdJobs->push($jobModel);
        }

        // Clear the jobs array and relatable model after releasing them.
        $this->jobs = [];
        $this->relatableModel = null;

        return $createdJobs;
    }

    /**
     * Associates the JobPollerManager instance with a specific block UUID.
     * This method is used to ensure that subsequent job operations are tied
     * to a specific UUID, allowing for better grouping and management.
     */
    public function onBlockUUID(string $blockUUID)
    {
        $this->blockUUID = $blockUUID;

        return $this;
    }

    /**
     * Retrieves a list of block UUIDs that contain jobs currently
     * in 'running' or 'failed' status. These UUIDs represent job
     * groups that are actively being processed or have encountered
     * errors.
     */
    protected function getBlockedUUIDs(): array
    {
        // Obtain all unique UUIDs where jobs are 'running' or 'failed'.
        return JobQueue::whereIn('status', ['running', 'failed'])
            ->pluck('block_uuid')
            ->unique()
            ->filter()
            ->toArray();
    }

    /**
     * Fetches all jobs that are eligible for processing, excluding
     * those associated with blocked UUIDs. This method respects the
     * maximum parallel jobs limit and ensures only jobs that are
     * ready can be picked up.
     */
    public function getEligibleJobs()
    {
        // Fetch all blocked UUIDs to exclude them from eligible jobs.
        $blockedUUIDs = $this->getBlockedUUIDs();

        // Retrieve pending jobs not blocked, limited by maxParallelJobs.
        $pendingJobs = JobQueue::where('status', 'pending')
            ->whereNotIn('block_uuid', $blockedUUIDs)
            ->orderBy('id', 'asc')
            ->limit($this->maxParallelJobs)
            ->lockForUpdate()
            ->get();

        return $pendingJobs;
    }

    /**
     * Handles the process of dispatching eligible jobs.
     * This method retrieves eligible jobs, processes them
     * individually, and ensures they are properly executed
     * on the defined queue.
     */
    public function handle()
    {
        // Obtain the hostname for logging the job execution environment.
        $hostname = gethostname();

        // Fetch all jobs that are eligible for processing.
        $eligibleJobs = $this->getEligibleJobs();

        // Exit if no jobs are available for processing.
        if ($eligibleJobs->isEmpty()) {
            return;
        }

        // Iterate over each eligible job and process it.
        foreach ($eligibleJobs as $job) {
            $this->processJob($job, $hostname);
        }
    }

    /**
     * Processes a specific job within a database transaction.
     * This ensures data integrity, updating the job status
     * to 'running' and assigning the hostname before dispatch.
     */
    protected function processJob(JobQueue $job, string $hostname): void
    {
        // Execute the job processing inside a database transaction.
        DB::transaction(function () use (&$job, $hostname) {
            // Attempt to lock the job row for exclusive processing.
            $lockedJob = $this->lockJob($job->id);

            // Update job status to 'running' if it's still 'pending'.
            if ($lockedJob && $lockedJob->status === 'pending') {
                $lockedJob->update([
                    'status' => 'running',
                    'hostname' => $hostname,
                    'started_at' => now()->valueOf(),
                ]);

                $job = $lockedJob;
            }
        });

        // Dispatch the job for execution.
        $this->dispatchJob($job);
    }

    /**
     * Attempts to lock a job row for processing.
     * This method ensures the job row is locked to prevent
     * concurrent modifications by other processes.
     */
    protected function lockJob(int $jobId): ?JobQueue
    {
        // Lock the job row for the given job ID.
        return JobQueue::lockForUpdate()->find($jobId);
    }

    /**
     * Dispatches the job to the Laravel queue system.
     * This method instantiates the job, checks for poller
     * instance compatibility, and dispatches it for execution.
     */
    protected function dispatchJob(JobQueue $job): void
    {
        try {
            // Instantiate the job class using the stored arguments.
            $jobInstance = new $job->class(
                ...json_decode($job->arguments, true)
            );

            // Set the Job Poller instance.
            $jobInstance->setJobPollerInstance($job);

            // Dispatch the job to the configured queue.
            dispatch($jobInstance)->onQueue($this->getQueueName());
        } catch (\Throwable $e) {
            // Mark the job as 'failed' if an error occurs.
            $job->update(['status' => 'failed']);
        }
    }

    /**
     * Retrieves the queue name from the configuration settings.
     * Defaults to the hostname if no queue name is explicitly set.
     */
    public function getQueueName(): string
    {
        // Fetch the queue name from configuration or default to hostname.
        return config(
            'nidavellir.system.job_poller.queue_name',
            gethostname()
        );
    }
}
