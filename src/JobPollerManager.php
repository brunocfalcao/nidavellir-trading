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

    public function __construct()
    {
        // Initialize with a new block UUID but avoid logging here
        $this->blockUUID = (string) Str::uuid();
        $this->currentIndex = 1;
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
        // Add job to the collection
        $this->jobs[] = [
            'class' => $className,
            'arguments' => $arguments,
            'index' => $this->currentIndex,
        ];

        // Log a single line
        Log::info('Job added: '.$className);

        // Increment the index for the next job
        $this->currentIndex++;

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
        $this->currentIndex = 1; // Reset the index when a new block is generated

        Log::info('New Block UUID generated or set: '.$this->blockUUID);

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
            // Create the job record in the database
            $jobModel = JobQueue::create([
                'class' => $job['class'],
                'arguments' => json_encode($job['arguments']), // Store arguments as JSON
                'status' => 'pending',
                'block_uuid' => $this->blockUUID,
                'index' => $job['index'],
            ]);

            Log::info('Job saved to the database: '.$jobModel->class);

            // Add the created job model to the collection
            $createdJobs->push($jobModel);
        }

        // Clear the jobs array after adding them to the database
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

        // Set the current index to the next available index within this block UUID
        $lastJob = JobQueue::where('block_uuid', $blockUUID)
            ->orderBy('index', 'desc')
            ->first();

        $this->currentIndex = $lastJob ? $lastJob->index + 1 : 1;

        Log::info('Using existing Block UUID: '.$this->blockUUID);

        return $this;
    }

    /**
     * Get the number of available workers
     *
     * @return int
     */
    public function getNumberOfAvailableWorkers()
    {
        // Fetch the total number of active workers from the ENV variable or assume 3 if not set
        $totalWorkers = (int) env('TOTAL_WORKERS', 3);

        // Calculate the number of jobs currently in a 'running' state
        $runningJobsCount = JobQueue::where('status', 'running')->count();

        $availableWorkers = max($totalWorkers - $runningJobsCount, 0);

        Log::info('Determined number of available workers: '.$availableWorkers);

        return $availableWorkers;
    }

    /**
     * Get the queue name that this job poller should use.
     *
     * @return string
     */
    public function getQueueName()
    {
        // Use the hostname as the default queue name if not specified in the ENV
        return env('JOB_POLLER_QUEUE_NAME', gethostname());
    }

    /**
     * Get eligible jobs based on the number of available workers
     *
     * @param  int  $numAvailableWorkers
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getEligibleJobs($numAvailableWorkers)
    {
        // Retrieve all pending jobs ordered by creation time
        $pendingJobs = JobQueue::where('status', 'pending')
            ->orderBy('created_at', 'asc')
            ->lockForUpdate()
            ->get();

        $eligibleJobs = collect();

        foreach ($pendingJobs as $job) {
            if ($job->block_uuid) {
                // Find the job with the lowest index in the block
                $nextJob = JobQueue::where('block_uuid', $job->block_uuid)
                    ->where('status', 'pending')
                    ->orderBy('index', 'asc')
                    ->lockForUpdate()
                    ->first();

                if ($nextJob) {
                    // Check if there's a job with an index just before this one that is running
                    $previousJob = JobQueue::where('block_uuid', $job->block_uuid)
                        ->where('index', $nextJob->index - 1)
                        ->where('status', 'running')
                        ->first();

                    // If no running previous job is found, mark this job as eligible
                    if (! $previousJob) {
                        $eligibleJobs->push($nextJob);
                    }
                }
            } else {
                // If there is no block_uuid, the job itself is eligible
                $eligibleJobs->push($job);
            }

            // Stop if we have collected enough jobs for the available workers
            if ($eligibleJobs->count() >= $numAvailableWorkers) {
                break;
            }
        }

        Log::info('Eligible jobs fetched: '.$eligibleJobs->count());

        return $eligibleJobs;
    }

    /**
     * Handle the job execution.
     */
    public function handle()
    {
        $hostname = gethostname();

        while (true) {
            $numAvailableWorkers = $this->getNumberOfAvailableWorkers();

            if ($numAvailableWorkers > 0) {
                $eligibleJobs = $this->getEligibleJobs($numAvailableWorkers);

                // If no more eligible jobs, break the loop
                if ($eligibleJobs->isEmpty()) {
                    Log::info('No more eligible jobs to run.');
                    break;
                }

                foreach ($eligibleJobs as $job) {
                    DB::transaction(function () use ($job, $hostname) {
                        // Mark job as running
                        $job->update([
                            'status' => 'running',
                            'hostname' => $hostname,
                            'started_at' => now(),
                        ]);

                        Log::info('Job started: '.$job->class);

                        try {
                            // Instantiate the job class with arguments
                            $jobInstance = new $job->class(...json_decode($job->arguments, true));

                            // Call the setJobPollerInstance method
                            if (method_exists($jobInstance, 'setJobPollerInstance')) {
                                $jobInstance->setJobPollerInstance($job);
                            }

                            // Dispatch the job asynchronously; workers will pick it up
                            dispatch($jobInstance)->onQueue($this->getQueueName());

                            Log::info('Job dispatched: '.$job->class);
                        } catch (\Throwable $e) {
                            // Log error and mark job as failed
                            Log::error('Failed to dispatch job: '.$job->class.' - '.$e->getMessage());
                            $job->update(['status' => 'failed']);
                        }
                    });
                }
            } else {
                Log::info('No available workers or eligible jobs to run.');
                break;
            }
        }
    }
}
