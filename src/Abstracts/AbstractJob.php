<?php

namespace Nidavellir\Trading\Abstracts;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Models\JobQueue;
use Throwable;

/**
 * AbstractJob defines the base structure for all API jobs that interact
 * with third-party systems. It manages logging, exception handling,
 * job completion, and failure tracking, providing a template for
 * executing and managing API-related tasks.
 *
 * - Manages job lifecycle (start, complete, fail).
 * - Logs job details to the JobQueue model.
 * - Provides structured handling for API logic and exceptions.
 */
abstract class AbstractJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Maximum number of attempts to run the job.
    protected int $maxAttempts = 3;

    // Delay in seconds before retrying the job after a failure.
    protected int $retryDelay = 5;

    // Determines if the job should fail on an HTTP error.
    protected bool $failOnHttpError = false;

    // The entry in the JobQueue that represents this job instance.
    protected ?JobQueue $jobQueueEntry = null;

    // Main method called to handle the execution of the job.
    public function handle()
    {
        $this->startJobLogging();
        $this->handleApiTransactionLogic();
    }

    // Executes the core API transaction logic within a try-catch block.
    public function handleApiTransactionLogic()
    {
        try {
            $this->executeApiLogic();
            $this->markJobAsComplete();
        } catch (Throwable $e) {
            $this->markJobAsFailed($e);
            $this->handleException($e);
        }
    }

    // Abstract method to be implemented by subclasses for API logic execution.
    abstract protected function executeApiLogic();

    // Initiates logging for the job when it starts.
    protected function startJobLogging(): void
    {
        $this->jobQueueEntry = JobQueue::create([
            'class' => get_class($this),
            'arguments' => json_encode($this->jobArguments()),
            'status' => 'running',
            'started_at' => now()->getPreciseTimestamp(3), // Start time in milliseconds.
            'hostname' => gethostname(),
        ]);
    }

    // Marks the job as complete and updates the job queue entry.
    protected function markJobAsComplete(): void
    {
        if ($this->jobQueueEntry) {
            $completedAt = now()->getPreciseTimestamp(3); // Completion time in milliseconds.
            $this->jobQueueEntry->update([
                'status' => 'completed',
                'completed_at' => $completedAt,
                'duration' => $completedAt - $this->jobQueueEntry->started_at,
            ]);
        }
    }

    // Marks the job as failed, capturing error details and updating the job queue entry.
    protected function markJobAsFailed(Throwable $e): void
    {
        if ($this->jobQueueEntry) {
            $completedAt = now()->getPreciseTimestamp(3); // Failure time in milliseconds.
            $this->jobQueueEntry->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => $completedAt,
                'duration' => $completedAt - $this->jobQueueEntry->started_at,
            ]);
        }
    }

    // Attaches a related model to the job entry in the job queue.
    public function attachRelatedModel($model): void
    {
        if ($this->jobQueueEntry && $model) {
            $this->jobQueueEntry->update([
                'related_id' => $model->getKey(),
                'related_type' => get_class($model),
            ]);
        }
    }

    // Returns all job arguments as an associative array.
    protected function jobArguments(): array
    {
        return get_object_vars($this);
    }

    // Handles exceptions by failing the job if an HTTP error occurs.
    protected function handleException(Throwable $e): void
    {
        if ($this->failOnHttpError && $this->isHttpError($e)) {
            $this->fail($e);
        }

        throw $e; // To be caught by Sentry.
    }

    // Determines if the given exception is an HTTP error.
    protected function isHttpError(Throwable $e): bool
    {
        return in_array($e->getCode(), [400, 401, 402, 403, 429, 500]);
    }

    // Marks the job as failed when the Laravel queue job fails.
    public function failed(Throwable $e)
    {
        $this->markJobAsFailed($e);
    }
}
