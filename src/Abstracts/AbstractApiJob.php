<?php

namespace Nidavellir\Trading\Abstracts;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Log;
use Throwable;

abstract class AbstractApiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $maxAttempts = 3;

    protected int $retryDelay = 5;

    protected bool $failOnHttpError = false;

    // Add this array to hold rate limit configurations
    protected array $rateLimitConfig = [];

    // The main entry point for the job
    public function handle()
    {
        $this->handleApiTransactionLogic();
    }

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

    // Add the setRateLimitConfig method
    protected function setRateLimitConfig(array $config): void
    {
        $this->rateLimitConfig = array_merge($this->rateLimitConfig, $config);
    }

    protected function handleException(Throwable $e): void
    {
        Log::error('API job encountered an error.', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'job_class' => get_class($this),
            'attempts' => $this->attempts(),
        ]);

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
        Log::critical('Job permanently failed after max attempts', [
            'job_class' => get_class($this),
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'attempts' => $this->attempts(),
        ]);
    }
}
