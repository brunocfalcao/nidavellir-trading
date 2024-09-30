<?php

namespace Nidavellir\Trading\Jobs\ApiJobFoundations;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Throwable;

/**
 * KuCoinApiJob provides a foundation for interacting with KuCoin's API.
 * It manages rate limit configurations, handles API logic execution,
 * and error handling specific to KuCoin's rate limits.
 *
 * - Configures rate limits based on VIP plan.
 * - Handles 429 and 403 HTTP errors for KuCoin.
 */
abstract class KuCoinApiJob extends AbstractJob
{
    protected string $apiCanonical = 'kucoin'; // Define API canonical

    // Prepares the job by applying the KuCoin rate limit configuration.
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

    // Applies rate limit configuration based on KuCoin rate limits.
    protected function applyRateLimitConfig(): void
    {
        // Retrieve the VIP plan configuration from Laravel config files.
        $plan = config('nidavellir.system.api.params.kucoin.plan', 'general');

        // Define rate limits based on different KuCoin API VIP plans.
        $rateLimits = [
            'general' => [
                'requests_per_second' => 10,   // 10 requests per second.
                'burst_requests' => 100,      // Allows bursts up to 100 requests.
                'retry_delay' => 1,           // Delay of 1 second for retries.
            ],
            'vip1' => [
                'requests_per_second' => 15,   // 15 requests per second.
                'burst_requests' => 150,      // Allows bursts up to 150 requests.
                'retry_delay' => 1,           // Delay of 1 second for retries.
            ],
            'vip2' => [
                'requests_per_second' => 20,   // 20 requests per second.
                'burst_requests' => 200,      // Allows bursts up to 200 requests.
                'retry_delay' => 1,           // Delay of 1 second for retries.
            ],
            'vip3' => [
                'requests_per_second' => 25,   // 25 requests per second.
                'burst_requests' => 250,      // Allows bursts up to 250 requests.
                'retry_delay' => 1,           // Delay of 1 second for retries.
            ],
            'vip4' => [
                'requests_per_second' => 30,   // 30 requests per second.
                'burst_requests' => 300,      // Allows bursts up to 300 requests.
                'retry_delay' => 1,           // Delay of 1 second for retries.
            ],
            'vip5' => [
                'requests_per_second' => 35,   // 35 requests per second.
                'burst_requests' => 350,      // Allows bursts up to 350 requests.
                'retry_delay' => 1,           // Delay of 1 second for retries.
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

    // Makes an API call while handling rate limit errors and KuCoin-specific checks.
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

            // Check for KuCoin-specific error handling.
            $this->checkForSpecificErrors($e);

            // Re-throw the exception for further handling.
            throw $e;
        }
    }

    // Handles KuCoin-specific error handling, focusing on rate limit issues.
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

        if ($e->getCode() === 403) {
            // Handle the 403 Forbidden error, which could indicate rate limit exceeded.
            Log::error("IP {$ipAddress} encountered a 403 error for {$this->apiCanonical}. Check API key restrictions or rate limits.");
        }
    }

    // Extracts KuCoin-specific rate limit headers without logging them.
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
