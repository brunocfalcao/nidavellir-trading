<?php

namespace Nidavellir\Trading\Jobs\ApiJobFoundations;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Nidavellir\Trading\Abstracts\AbstractJob;
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
abstract class BinanceApiJob extends AbstractJob
{
    protected string $apiCanonical = 'binance'; // Define API canonical

    // Prepares the job by applying rate limit configuration.
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

    // Abstract method for executing API logic, implemented by subclasses.
    abstract protected function compute();

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

            // Reset the rate limit entry for this IP after a successful request
            $ipAddress = gethostbyname(gethostname());
            DB::table('rate_limits')
                ->where('ip_address', $ipAddress)
                ->where('api_canonical', $this->apiCanonical)
                ->delete();

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
        $ipAddress = gethostbyname(gethostname());

        if ($e->getCode() === 429) {
            $retryDelay = $this->rateLimitConfig['retry_delay'];

            // Update or insert the rate limit record for this IP
            DB::table('rate_limits')->updateOrInsert(
                ['ip_address' => $ipAddress, 'api_canonical' => $this->apiCanonical],
                ['retry_after' => Carbon::now()->timestamp + $retryDelay]
            );

            Log::info("Rate limit exceeded for IP {$ipAddress} on {$this->apiCanonical}, backing off for {$retryDelay} seconds.");
            $this->release($retryDelay); // Re-queue job to retry after the delay
        }

        if ($e->getCode() === 418) {
            // Block this IP for a longer duration (e.g., 24 hours)
            DB::table('rate_limits')->updateOrInsert(
                ['ip_address' => $ipAddress, 'api_canonical' => $this->apiCanonical],
                ['retry_after' => Carbon::now()->timestamp + 86400] // 24 hours
            );

            Log::error("IP {$ipAddress} banned by Binance. Further attempts are blocked for 24 hours.");
        }
    }
}
