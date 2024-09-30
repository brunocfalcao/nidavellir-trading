<?php

namespace Nidavellir\Trading\Abstracts;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Models\JobQueue;
use Throwable;

abstract class AbstractApiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $maxAttempts = 3;
    protected int $retryDelay = 5;
    protected bool $failOnHttpError = false;
    protected ?JobQueue $jobQueueEntry = null;

    public function handle()
    {
        $this->startJobLogging();
        $this->handleApiTransactionLogic();
    }

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

    abstract protected function executeApiLogic();

    protected function startJobLogging(): void
    {
        $this->jobQueueEntry = JobQueue::create([
            'class' => get_class($this),
            'arguments' => json_encode($this->jobArguments()),
            'status' => 'running',
            'started_at' => now()->getPreciseTimestamp(3), // Start time in milliseconds
            'hostname' => gethostname(),
        ]);
    }

    protected function markJobAsComplete(): void
    {
        if ($this->jobQueueEntry) {
            $completedAt = now()->getPreciseTimestamp(3); // Completion time in milliseconds
            $this->jobQueueEntry->update([
                'status' => 'completed',
                'completed_at' => $completedAt,
                'duration' => $completedAt - $this->jobQueueEntry->started_at,
            ]);
        }

        info_multiple(
            'Job completed successfully',
            'Job Class: ' . get_class($this),
            'Job ID: ' . $this->jobQueueEntry->id,
            'Duration: ' . $this->jobQueueEntry->duration . ' ms'
        );
    }

    protected function markJobAsFailed(Throwable $e): void
    {
        if ($this->jobQueueEntry) {
            $completedAt = now()->getPreciseTimestamp(3); // Failure time in milliseconds
            $this->jobQueueEntry->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => $completedAt,
                'duration' => $completedAt - $this->jobQueueEntry->started_at,
            ]);
        }

        info_multiple(
            'Job failed',
            'Job Class: ' . get_class($this),
            'Job ID: ' . $this->jobQueueEntry->id,
            'Error Message: ' . $e->getMessage(),
            'Trace: ' . $e->getTraceAsString()
        );
    }

    // Method to attach a related model to the job entry
    public function attachRelatedModel($model): void
    {
        if ($this->jobQueueEntry && $model) {
            $this->jobQueueEntry->update([
                'related_id' => $model->getKey(),
                'related_type' => get_class($model),
            ]);
        }
    }

    protected function jobArguments(): array
    {
        return get_object_vars($this);
    }

    protected function handleException(Throwable $e): void
    {
        info_multiple(
            'API job encountered an error.',
            'Exception: ' . $e->getMessage(),
            'Trace: ' . $e->getTraceAsString(),
            'Job Class: ' . get_class($this),
            'Attempts: ' . $this->attempts()
        );

        if ($this->failOnHttpError && $this->isHttpError($e)) {
            $this->fail($e);
        }
    }

    protected function isHttpError(Throwable $e): bool
    {
        return in_array($e->getCode(), [400, 401, 402, 403, 429, 500]);
    }

    public function failed(Throwable $e)
    {
        $this->markJobAsFailed($e);
        info_multiple(
            'Job permanently failed after max attempts',
            'Job Class: ' . get_class($this),
            'Exception: ' . $e->getMessage(),
            'Trace: ' . $e->getTraceAsString(),
            'Attempts: ' . $this->attempts()
        );
    }
}
