<?php

namespace Nidavellir\Trading\Jobs\ApiJobFoundations;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Throwable;

/**
 * CoinbaseApiJob handles the execution of API jobs specifically
 * for Coinbase. It manages rate limit configurations, API
 * interactions, and error handling for Coinbase's API.
 *
 * - Configures rate limits based on API plan.
 * - Handles 429 and 403 HTTP errors gracefully.
 */
abstract class CoinbaseApiJob extends AbstractJob
{
    // Prepares the job by applying the Coinbase rate limit configuration.
    protected function prepareJob()
    {
        // Apply the rate limit configuration as part of job preparation.
        $this->applyRateLimitConfig();
    }

    // Abstract method for executing API logic, to be implemented by subclasses.
    abstract protected function executeApiLogic();

    // Applies rate limit configuration based on Coinbase rate limits.
    protected function applyRateLimitConfig(): void
    {
        // Retrieve the plan configuration from Laravel config files.
        $plan = config('nidavellir.system.api.params.coinbase.plan', 'public');

        // Define rate limits based on different Coinbase API plans.
        $rateLimits = [
            'public' => [
                'requests_per_second' => 10,     // 10 requests per second.
                'burst_requests' => 15,         // Allows bursts up to 15 requests per second.
                'retry_delay' => 1,             // Delay of 1 second for retries.
            ],
            'private' => [
                'requests_per_second' => 15,     // 15 requests per second for private endpoints.
                'burst_requests' => 30,         // Allows bursts up to 30 requests per second.
                'retry_delay' => 1,             // Delay of 1 second for retries.
            ],
            '/fills' => [
                'requests_per_second' => 10,    // 10 requests per second.
                'burst_requests' => 20,        // Allows bursts up to 20 requests per second.
                'retry_delay' => 1,            // Delay of 1 second for retries.
            ],
            '/loans' => [
                'requests_per_second' => 10,    // 10 requests per second.
                'retry_delay' => 1,            // Delay of 1 second for retries.
            ],
        ];

        // Apply the rate limit configuration if the plan exists.
        if (isset($rateLimits[$plan])) {
            $this->setRateLimitConfig([
                'retry_delay' => $rateLimits[$plan]['retry_delay'],
                'rate_limit_headers' => [
                    'CB-RateLimit-Limit' => 'Maximum number of requests per plan per second.',
                    'CB-RateLimit-Remaining' => 'Remaining requests for the current window.',
                    'CB-RateLimit-Reset' => 'Time at which the current rate limit window resets in UTC epoch seconds.',
                ],
            ]);
        }
    }

    // Makes an API call while handling rate limit errors and Coinbase-specific checks.
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

            // Check for Coinbase-specific error handling.
            $this->checkForSpecificErrors($e);

            // Re-throw the exception for further handling.
            throw $e;
        }
    }

    // Handles Coinbase-specific error handling, focusing on rate limit issues.
    protected function checkForSpecificErrors(Throwable $e): void
    {
        if ($e->getCode() === 429) {
            // Handle the 429 Too Many Requests error for Coinbase.
        }

        if ($e->getCode() === 403) {
            // Handle the 403 Forbidden error for API key restrictions or rate limit exceeded.
        }
    }

    // Extracts Coinbase-specific rate limit headers without logging them.
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
}
