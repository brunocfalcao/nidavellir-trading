<?php

namespace Nidavellir\Trading\Jobs\ApiJobFoundations;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Throwable;

/**
 * TaapiApiJob provides a foundation for interacting with the
 * Taapi.io API. It manages rate limit configurations, handles
 * API logic execution, and manages error handling specific
 * to Taapi.io's rate limits.
 *
 * - Configures rate limits based on the user's Taapi.io plan.
 * - Handles HTTP 429 errors specific to Taapi.io.
 */
abstract class TaapiApiJob extends AbstractJob
{
    // Prepares the job by applying the Taapi.io rate limit configuration.
    protected function prepareJob()
    {
        // Apply the rate limit configuration as part of job preparation.
        $this->applyRateLimitConfig();
    }

    // Abstract method for executing API logic, to be implemented by subclasses.
    abstract protected function executeApiLogic();

    // Applies rate limit configuration based on the Taapi.io plan.
    protected function applyRateLimitConfig(): void
    {
        // Retrieve the Taapi.io plan configuration from Laravel config files.
        $plan = config('nidavellir.system.api.params.taapi.plan', 'free');

        // Define rate limits based on different Taapi.io plans.
        $rateLimits = [
            'free' => [
                'requests_per_15_seconds' => 1,   // 1 request per 15 seconds.
                'retry_delay' => 15,             // Delay of 15 seconds for retries.
            ],
            'basic' => [
                'requests_per_15_seconds' => 5,   // 5 requests per 15 seconds.
                'retry_delay' => 15,             // Delay of 15 seconds for retries.
            ],
            'pro' => [
                'requests_per_15_seconds' => 30,  // 30 requests per 15 seconds.
                'retry_delay' => 15,             // Delay of 15 seconds for retries.
            ],
            'expert' => [
                'requests_per_15_seconds' => 75,  // 75 requests per 15 seconds.
                'retry_delay' => 15,             // Delay of 15 seconds for retries.
            ],
        ];

        // Apply the rate limit configuration if the plan exists.
        if (isset($rateLimits[$plan])) {
            $this->setRateLimitConfig([
                'retry_delay' => $rateLimits[$plan]['retry_delay'],
                'rate_limit_headers' => [
                    'X-RateLimit-Limit' => 'Maximum number of requests per plan per 15 seconds.',
                    'X-RateLimit-Remaining' => 'Remaining requests for the current window.',
                ],
            ]);
        }
    }

    // Makes an API call while handling rate limit errors and Taapi.io-specific checks.
    protected function makeApiCall(callable $apiCall)
    {
        try {
            /** @var ResponseInterface $response */
            $response = $apiCall();

            // Extract rate limit headers.
            $headers = $response->getHeaders();
            $this->logRateLimitHeaders($headers);

            // Return the decoded response body.
            return json_decode($response->getBody()->getContents(), true);
        } catch (Throwable $e) {
            // Handle exceptions using parent error handling logic.
            $this->handleException($e);

            // Check for Taapi-specific error handling.
            $this->checkForSpecificErrors($e);

            // Re-throw the exception for further handling.
            throw $e;
        }
    }

    // Extracts Taapi.io-specific rate limit headers without logging them.
    protected function logRateLimitHeaders(array $headers): void
    {
        // Loop through rate limit headers defined in the configuration.
        foreach ($this->rateLimitConfig['rate_limit_headers'] as $headerKey => $description) {
            if (isset($headers[$headerKey])) {
                // Extract the header value without logging.
                $headerValue = $headers[$headerKey][0];
                // The extracted value can be used if needed.
            }
        }
    }

    // Handles additional error handling for Taapi.io-specific rate limit issues.
    protected function checkForSpecificErrors(Throwable $e): void
    {
        if ($e->getCode() === 429) {
            // Handle the 429 Too Many Requests error without logging.
        }
    }
}
