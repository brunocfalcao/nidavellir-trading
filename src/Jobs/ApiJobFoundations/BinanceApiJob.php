<?php

namespace Nidavellir\Trading\Jobs\ApiJobFoundations;

use Nidavellir\Trading\Abstracts\AbstractApiJob;
use Throwable;

/**
 * BinanceApiJob provides a foundation for interacting with Binance's API.
 * It manages rate limit configurations, handles API logic execution, and
 * manages error handling specific to Binance, including rate limit and
 * IP ban scenarios.
 *
 * - Implements rate limit headers for Binance's API.
 * - Handles 429 (rate limit) and 418 (IP ban) errors gracefully.
 */
abstract class BinanceApiJob extends AbstractApiJob
{
    // Prepares the job by applying rate limit configuration.
    protected function prepareJob()
    {
        // Apply the rate limit configuration as part of job preparation.
        $this->applyRateLimitConfig();
    }

    // Abstract method for executing API logic, implemented by subclasses.
    abstract protected function executeApiLogic();

    // Applies rate limit configuration based on Binance's API documentation.
    protected function applyRateLimitConfig(): void
    {
        // Set default retry delays based on Binance documentation recommendations.
        $retryDelay = 60; // Default to 60 seconds for 429 errors.

        // Define rate limit configuration headers.
        $rateLimits = [
            'retry_delay' => $retryDelay,
            'rate_limit_headers' => [
                'X-MBX-USED-WEIGHT-1m' => 'Current used weight for the IP per minute',
                'X-MBX-ORDER-COUNT-1m' => 'Current order count for the account per minute',
            ],
        ];

        // Set the rate limit configuration in the job context.
        $this->setRateLimitConfig($rateLimits);
    }

    // Makes an API call with error handling and rate limit extraction.
    protected function makeApiCall(callable $apiCall)
    {
        try {
            /** @var ResponseInterface $response */
            $response = $apiCall();

            // Extract rate limit headers without logging them.
            $headers = $response->getHeaders();
            $this->logRateLimitHeaders($headers);

            // Return the decoded response body.
            return json_decode($response->getBody()->getContents(), true);
        } catch (Throwable $e) {
            // Handle the exception using the parent error handling logic.
            $this->handleException($e);

            // Check for Binance-specific error handling.
            $this->checkForSpecificErrors($e);

            // Re-throw the exception to allow further handling.
            throw $e;
        }
    }

    // Extracts and processes Binance-specific rate limit headers.
    protected function logRateLimitHeaders(array $headers): void
    {
        // Loop through defined rate limit headers for extraction.
        foreach ($this->rateLimitConfig['rate_limit_headers'] as $headerKey => $description) {
            if (isset($headers[$headerKey])) {
                // Extract the header value without logging.
                $headerValue = $headers[$headerKey][0];
                // The value can be used here if necessary.
            }
        }
    }

    // Handles Binance-specific rate limit and IP ban errors.
    protected function checkForSpecificErrors(Throwable $e): void
    {
        if ($e->getCode() === 429) {
            // Handling logic for 429 (rate limit exceeded) error without logging.
        }

        if ($e->getCode() === 418) {
            // Handling logic for 418 (IP banned) error without logging.
        }
    }
}
