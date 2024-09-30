<?php

namespace Nidavellir\Trading\Jobs\ApiJobFoundations;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Nidavellir\Trading\Abstracts\AbstractJob;
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
abstract class CoinmarketCapApiJob extends AbstractJob
{
    protected string $apiCanonical = 'coinmarketcap'; // Define API canonical

    // Prepares the job by applying the CoinMarketCap rate limit configuration.
    protected function prepareJob()
    {
        $this->applyRateLimitConfig();

        // Get the worker's IP address
        $ipAddress = gethostbyname(gethostname());

        // Check the rate limit table for this IP and API
        $rateLimit = DB::table('rate_limits')
            ->where('ip_address', $ipAddress)
            ->where('api_canonical', $this->apiCanonical)
            ->first();

        // If rate limit exists and the retry_after is in the future, stop processing
        if ($rateLimit && Carbon::now()->timestamp < $rateLimit->retry_after) {
            $retryIn = $rateLimit->retry_after - Carbon::now()->timestamp;
            Log::info("Rate limit active for IP {$ipAddress} on {$this->apiCanonical}. Releasing job to retry after {$retryIn} seconds.");
            $this->release($retryIn); // Re-queue the job after the retry period

            return;
        }
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

    // Makes an API call while handling rate limit errors and CoinMarketCap-specific checks.
    protected function makeApiCall(callable $apiCall)
    {
        try {
            /** @var ResponseInterface $response */
            $response = $apiCall();

            // Extract rate limit headers.
            $headers = $response->getHeaders();
            $this->logRateLimitHeaders($headers);

            // Reset the rate limit entry for this IP after a successful request
            $ipAddress = gethostbyname(gethostname());
            DB::table('rate_limits')
                ->where('ip_address', $ipAddress)
                ->where('api_canonical', $this->apiCanonical)
                ->delete();

            // Return the decoded response body.
            return json_decode($response->getBody()->getContents(), true);
        } catch (Throwable $e) {
            // Handle exceptions using parent error handling logic.
            $this->handleException($e);

            // Check for CoinMarketCap-specific error handling.
            $this->checkForSpecificErrors($e);

            // Re-throw the exception for further handling.
            throw $e;
        }
    }

    // Handles additional error handling for CoinMarketCap-specific error codes.
    protected function checkForSpecificErrors(Throwable $e): void
    {
        $ipAddress = gethostbyname(gethostname());

        // Define error messages for specific CoinMarketCap error codes.
        $errorCodes = [
            1008 => 'Minute rate limit reached.',
            1009 => 'Daily rate limit reached.',
            1010 => 'Monthly rate limit reached.',
            1011 => 'IP rate limit reached.',
        ];

        // Check if the exception has a valid error code that matches CoinMarketCap errors.
        if (isset($errorCodes[$e->getCode()])) {
            $retryDelay = $this->rateLimitConfig['retry_delay'];

            // Update or insert the rate limit record for this IP
            DB::table('rate_limits')->updateOrInsert(
                ['ip_address' => $ipAddress, 'api_canonical' => $this->apiCanonical],
                ['retry_after' => Carbon::now()->timestamp + $retryDelay]
            );

            Log::info("CoinMarketCap error {$e->getCode()} for IP {$ipAddress} on {$this->apiCanonical}: {$errorCodes[$e->getCode()]}. Retrying after {$retryDelay} seconds.");
            $this->release($retryDelay); // Re-queue job to retry after the delay
        }
    }

    // Extracts CoinMarketCap-specific rate limit headers without logging them.
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
