<?php

namespace Nidavellir\Trading\Jobs\ApiJobFoundations;

use Nidavellir\Trading\Abstracts\AbstractApiJob;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class CoinmarketCapApiJob extends AbstractApiJob
{
    protected function prepareJob()
    {
        // Apply the rate limit configuration as part of job preparation
        $this->applyRateLimitConfig();
    }

    // Override the handleExchangeLogic method to include preparation logic
    protected function handleExchangeLogic()
    {
        // Ensure the rate limit configuration is applied
        $this->prepareJob();

        // Let child classes handle the actual API logic
        $this->executeCoinmarketCapLogic();
    }

    // Abstract method to be implemented by child classes for their specific logic
    abstract protected function executeCoinmarketCapLogic();

    // Apply rate limit configuration based on CoinMarketCap plan
    protected function applyRateLimitConfig(): void
    {
        $plan = config('nidavellir.system.api.params.coinmarketcap.plan', 'free');

        $rateLimits = [
            'free' => [
                'minute_limit' => 10,
                'daily_limit' => 500,
                'retry_delay' => 60,
            ],
            'basic' => [
                'minute_limit' => 50,
                'daily_limit' => 1000,
                'retry_delay' => 30,
            ],
            'professional' => [
                'minute_limit' => 250,
                'daily_limit' => 5000,
                'retry_delay' => 10,
            ],
            'enterprise' => [
                'minute_limit' => 500,
                'daily_limit' => 10000,
                'retry_delay' => 5,
            ],
        ];

        if (isset($rateLimits[$plan])) {
            $this->setRateLimitConfig([
                'retry_delay' => $rateLimits[$plan]['retry_delay'],
                'rate_limit_headers' => [
                    'X-RateLimit-Limit' => 'Maximum number of requests per plan',
                    'X-RateLimit-Remaining' => 'Remaining requests for the current window',
                    'X-RateLimit-Reset' => 'Time at which the current rate limit window resets in UTC epoch seconds',
                ],
            ]);
        }
    }

    // Additional error handling for CoinMarketCap-specific error codes
    protected function checkForSpecificErrors(Throwable $e): void
    {
        $errorCodes = [
            1008 => 'Minute rate limit reached.',
            1009 => 'Daily rate limit reached.',
            1010 => 'Monthly rate limit reached.',
            1011 => 'IP rate limit reached.',
        ];

        if (method_exists($e, 'getCode') && isset($errorCodes[$e->getCode()])) {
            Log::warning("CoinMarketCap API error: {$errorCodes[$e->getCode()]}", [
                'error_code' => $e->getCode(),
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
