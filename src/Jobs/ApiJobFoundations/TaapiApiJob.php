<?php

namespace Nidavellir\Trading\Jobs\ApiJobFoundations;

use Nidavellir\Trading\Abstracts\AbstractApiJob;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class TaapiApiJob extends AbstractApiJob
{
    protected function prepareJob()
    {
        // Apply the rate limit configuration as part of job preparation
        $this->applyRateLimitConfig();
    }

    // Only implement the executeApiLogic method, allowing the parent to handle error management
    abstract protected function executeApiLogic();

    // Apply rate limit configuration based on Taapi.io plan
    protected function applyRateLimitConfig(): void
    {
        $plan = config('nidavellir.system.api.params.taapi.plan', 'free');

        $rateLimits = [
            'free' => [
                'requests_per_15_seconds' => 1,
                'retry_delay' => 15, // Retry delay in seconds
            ],
            'basic' => [
                'requests_per_15_seconds' => 5,
                'retry_delay' => 15,
            ],
            'pro' => [
                'requests_per_15_seconds' => 30,
                'retry_delay' => 15,
            ],
            'expert' => [
                'requests_per_15_seconds' => 75,
                'retry_delay' => 15,
            ],
        ];

        if (isset($rateLimits[$plan])) {
            $this->setRateLimitConfig([
                'retry_delay' => $rateLimits[$plan]['retry_delay'],
                'rate_limit_headers' => [
                    'X-RateLimit-Limit' => 'Maximum number of requests per plan per 15 seconds',
                    'X-RateLimit-Remaining' => 'Remaining requests for the current window',
                ],
            ]);
        }
    }

    // Handling rate limit errors and additional Taapi.io-specific checks
    protected function makeApiCall(callable $apiCall)
    {
        try {
            /** @var ResponseInterface $response */
            $response = $apiCall();

            // Extract rate limit headers for logging and monitoring
            $headers = $response->getHeaders();
            $this->logRateLimitHeaders($headers);

            return json_decode($response->getBody()->getContents(), true);
        } catch (Throwable $e) {
            $this->handleException($e);
            $this->checkForSpecificErrors($e); // Check for Taapi-specific error handling
            throw $e;
        }
    }

    // Logs Taapi.io-specific rate limit headers for monitoring
    protected function logRateLimitHeaders(array $headers): void
    {
        foreach ($this->rateLimitConfig['rate_limit_headers'] as $headerKey => $description) {
            if (isset($headers[$headerKey])) {
                Log::info("Taapi.io rate limit info ($description):", [
                    $headerKey => $headers[$headerKey][0],
                ]);
            }
        }
    }

    // Additional error handling for Taapi.io-specific rate limit issues
    protected function checkForSpecificErrors(Throwable $e): void
    {
        if ($e->getCode() === 429) {
            Log::warning('Taapi.io API rate limit hit: 429 Too Many Requests', [
                'message' => 'You have exceeded your request limit for Taapi.io!',
            ]);
        }
    }
}
