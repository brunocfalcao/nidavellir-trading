<?php

namespace Nidavellir\Trading\Abstracts;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

abstract class AbstractApiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $maxAttempts = 3;
    protected int $retryDelay = 5;
    protected bool $failOnHttpError = false;

    // The main entry point for the job
    public function handle()
    {
        $this->handleApiTransactionLogic();
    }

    // The core method that handles transaction logic with try-catch
    public function handleApiTransactionLogic()
    {
        try {
            $this->executeApiLogic();
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    // Abstract method for specific API job logic that child classes will implement
    abstract protected function executeApiLogic();

    protected function setRateLimitConfig(array $config): void
    {
        $this->rateLimitConfig = array_merge($this->rateLimitConfig, $config);
    }

    protected function handleException(Throwable $e): void
    {
        // Use the info_multiple() helper method for logging
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
        // Use the info_multiple() helper method for logging
        info_multiple(
            'Job permanently failed after max attempts',
            'Job Class: ' . get_class($this),
            'Exception: ' . $e->getMessage(),
            'Trace: ' . $e->getTraceAsString(),
            'Attempts: ' . $this->attempts()
        );
    }
}
