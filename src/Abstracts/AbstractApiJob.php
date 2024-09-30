<?php

namespace Nidavellir\Trading\Abstracts;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use Carbon\Carbon;
use Log;
use Psr\Http\Message\ResponseInterface;

abstract class AbstractApiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $maxAttempts = 3;
    protected int $retryDelay = 5;
    protected bool $failOnHttpError = false;

    // Rate limit configuration
    protected array $rateLimitConfig = [
        'error_codes' => [429],     // Default error code for rate limiting
        'retry_delay' => 60,        // Default retry delay in seconds
        'rate_limit_headers' => [], // Headers to monitor rate limits
    ];

    // Abstract method for handling API logic; to be implemented by child jobs
    abstract protected function handleExchangeLogic();

    // Entry point for handling the job
    public function handle()
    {
        $startTime = Carbon::now();

        try {
            $this->beforeRequest();
            $this->handleExchangeLogic();
            $this->afterSuccess($startTime);
        } catch (Throwable $e) {
            $this->handleException($e);

            if ($this->isRateLimitError($e)) {
                $retryDelay = $this->getRateLimitRetryDelay();
                $this->release($retryDelay);

                Log::warning('Job delayed due to rate limit hit, retrying after ' . $retryDelay . ' seconds', [
                    'job_class' => get_class($this),
                    'attempts' => $this->attempts(),
                ]);
            } else {
                $this->retryJob($e);
            }
        }
    }

    // Helper method to handle API calls with error handling and rate limit logging
    protected function makeApiCall(callable $apiCall)
    {
        try {
            /** @var ResponseInterface $response */
            $response = $apiCall();

            // Inspect and log headers for rate limit handling
            $headers = $response->getHeaders();
            $this->logRateLimitHeaders($headers);

            // Return the JSON-decoded response body as an associative array
            return json_decode($response->getBody()->getContents(), true);
        } catch (Throwable $e) {
            $this->handleException($e);
            throw $e;
        }
    }

    // Logs rate limit-related headers for monitoring
    protected function logRateLimitHeaders(array $headers): void
    {
        foreach ($this->rateLimitConfig['rate_limit_headers'] as $headerKey => $description) {
            if (isset($headers[$headerKey])) {
                Log::info("Rate limit info ($description):", [
                    $headerKey => $headers[$headerKey][0],
                ]);
            }
        }
    }

    // Retry the job if an exception occurs
    protected function retryJob(Throwable $e): void
    {
        if ($this->attempts() < $this->maxAttempts) {
            $this->release($this->retryDelay);
        } else {
            $this->fail($e);
        }
    }

    // Check if the error was due to rate limiting
    protected function isRateLimitError(Throwable $e): bool
    {
        return in_array($e->getCode(), $this->rateLimitConfig['error_codes']);
    }

    // Retrieve the retry delay from the rate limit configuration
    protected function getRateLimitRetryDelay(): int
    {
        return $this->rateLimitConfig['retry_delay'];
    }

    // Set rate limit configuration for child jobs to customize rate limit handling
    protected function setRateLimitConfig(array $config): void
    {
        $this->rateLimitConfig = array_merge($this->rateLimitConfig, $config);
    }

    // Method executed after successful job completion
    protected function afterSuccess($startTime): void
    {
        $duration = $startTime->diffInMilliseconds(Carbon::now());
        Log::info('Job executed successfully', [
            'duration_ms' => $duration,
            'job_class' => get_class($this),
        ]);
    }

    // Called before making an API request, can be overridden by child jobs
    protected function beforeRequest(): void
    {
        // Custom logic to be implemented by child jobs if needed
    }

    // Handles exceptions and logging
    protected function handleException(Throwable $e): void
    {
        Log::error('Exchange API call failed', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'job_class' => get_class($this),
            'attempts' => $this->attempts(),
        ]);

        if ($this->failOnHttpError && $this->isHttpError($e)) {
            $this->fail($e);
        }
    }

    // Checks for HTTP errors
    protected function isHttpError(Throwable $e): bool
    {
        return method_exists($e, 'getCode') && in_array($e->getCode(), [400, 401, 402, 403, 429, 500]);
    }

    // Handle job failure when max attempts are reached
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
