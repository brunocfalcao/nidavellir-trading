<?php

namespace Nidavellir\Trading\Jobs\ApiJobFoundations;

use Illuminate\Support\Facades\Log;
use Nidavellir\Trading\Abstracts\AbstractApiJob;
use Throwable;

abstract class BinanceApiJob extends AbstractApiJob
{
    protected function prepareJob()
    {
        // Apply the rate limit configuration as part of job preparation
        $this->applyRateLimitConfig();
    }

    // Only implement the executeApiLogic method, allowing the parent to handle error management
    abstract protected function executeApiLogic();

    // Apply rate limit configuration based on Binance limits
    protected function applyRateLimitConfig(): void
    {
        // Set default retry delays based on Binance documentation recommendations
        $retryDelay = 60; // Default to 60 seconds for 429 errors
        $rateLimits = [
            'retry_delay' => $retryDelay,
            'rate_limit_headers' => [
                'X-MBX-USED-WEIGHT-1m' => 'Current used weight for the IP per minute',
                'X-MBX-ORDER-COUNT-1m' => 'Current order count for the account per minute',
            ],
        ];

        $this->setRateLimitConfig($rateLimits);
    }

    // Handling rate limit errors and additional Binance-specific checks
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
            $this->checkForSpecificErrors($e); // Check for Binance-specific error handling
            throw $e;
        }
    }

    // Logs Binance-specific rate limit headers for monitoring
    protected function logRateLimitHeaders(array $headers): void
    {
        foreach ($this->rateLimitConfig['rate_limit_headers'] as $headerKey => $description) {
            if (isset($headers[$headerKey])) {
                Log::info("Binance rate limit info ($description):", [
                    $headerKey => $headers[$headerKey][0],
                ]);
            }
        }
    }

    // Additional error handling for Binance-specific rate limit issues
    protected function checkForSpecificErrors(Throwable $e): void
    {
        if ($e->getCode() === 429) {
            Log::warning('Binance API rate limit hit: 429 Too Many Requests', [
                'message' => 'The request weight exceeded the allowed limit.',
            ]);
        }

        if ($e->getCode() === 418) {
            Log::critical('Binance API rate limit hit: 418 IP Ban', [
                'message' => 'Your IP has been banned. Back off and avoid making further requests.',
            ]);
        }
    }
}
