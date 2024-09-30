<?php

namespace Nidavellir\Trading\Jobs\ApiJobFoundations;

use Nidavellir\Trading\Abstracts\AbstractApiJob;
use Throwable;

/**
 * CoinmarketCapApiJob provides a foundation for interacting with the
 * CoinMarketCap API. It manages rate limit configurations, handles
 * API logic execution, and manages error handling specific to
 * CoinMarketCap's API plan limits.
 *
 * - Configures rate limits based on API subscription plans.
 * - Manages 1008, 1009, 1010, and 1011 error codes specific to CoinMarketCap.
 */
abstract class CoinmarketCapApiJob extends AbstractApiJob
{
    // Prepares the job by applying the CoinMarketCap rate limit configuration.
    protected function prepareJob()
    {
        // Apply the rate limit configuration as part of job preparation.
        $this->applyRateLimitConfig();
    }

    // Abstract method for executing API logic, to be implemented by subclasses.
    abstract protected function executeApiLogic();

    // Applies rate limit configuration based on CoinMarketCap plan.
    protected function applyRateLimitConfig(): void
    {
        // Retrieve the API plan configuration from Laravel config files.
        $plan = config('nidavellir.system.api.params.coinmarketcap.plan', 'free');

        // Define rate limits based on different CoinMarketCap API plans.
        $rateLimits = [
            'free' => [
                'minute_limit' => 10,      // 10 requests per minute.
                'daily_limit' => 500,      // 500 requests per day.
                'retry_delay' => 60,       // Delay of 60 seconds for retries.
            ],
            'basic' => [
                'minute_limit' => 50,      // 50 requests per minute.
                'daily_limit' => 1000,     // 1,000 requests per day.
                'retry_delay' => 30,       // Delay of 30 seconds for retries.
            ],
            'professional' => [
                'minute_limit' => 250,     // 250 requests per minute.
                'daily_limit' => 5000,     // 5,000 requests per day.
                'retry_delay' => 10,       // Delay of 10 seconds for retries.
            ],
            'enterprise' => [
                'minute_limit' => 500,     // 500 requests per minute.
                'daily_limit' => 10000,    // 10,000 requests per day.
                'retry_delay' => 5,        // Delay of 5 seconds for retries.
            ],
        ];

        // Apply the rate limit configuration if the plan exists.
        if (isset($rateLimits[$plan])) {
            $this->setRateLimitConfig([
                'retry_delay' => $rateLimits[$plan]['retry_delay'],
                'rate_limit_headers' => [
                    'X-RateLimit-Limit' => 'Maximum number of requests per plan.',
                    'X-RateLimit-Remaining' => 'Remaining requests for the current window.',
                    'X-RateLimit-Reset' => 'Time at which the current rate limit window resets in UTC epoch seconds.',
                ],
            ]);
        }
    }

    // Handles additional error handling for CoinMarketCap-specific error codes.
    protected function checkForSpecificErrors(Throwable $e): void
    {
        // Define error messages for specific CoinMarketCap error codes.
        $errorCodes = [
            1008 => 'Minute rate limit reached.',
            1009 => 'Daily rate limit reached.',
            1010 => 'Monthly rate limit reached.',
            1011 => 'IP rate limit reached.',
        ];

        // Check if the exception has a valid error code that matches CoinMarketCap errors.
        if (method_exists($e, 'getCode') && isset($errorCodes[$e->getCode()])) {
            // Handle the error based on the specific code, without logging.
            $errorCode = $e->getCode();
            $errorMessage = $e->getMessage();
            // The extracted errorCode and errorMessage can be utilized here as needed.
        }
    }
}
