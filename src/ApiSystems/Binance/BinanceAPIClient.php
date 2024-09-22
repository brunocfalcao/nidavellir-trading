<?php

namespace Nidavellir\Trading\ApiSystems\Binance;

use Binance\Util\Url;
use GuzzleHttp\Exception\ClientException as GuzzleClientException;
use Nidavellir\Trading\Exceptions\ApiException;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Models\ApiRequestLog;
use Nidavellir\Trading\Models\EndpointWeight;
use Nidavellir\Trading\Models\Exchange;
use Nidavellir\Trading\Models\IpRequestWeight;

class BinanceAPIClient
{
    private $baseURL;

    private $key;

    private $secret;

    private $privateKey;

    private $logger;

    private $timeout;

    private $showWeightUsage;

    private $showHeader;

    private $httpRequest = null;

    public function __construct($args = [])
    {
        $this->baseURL = $args['baseURL'] ?? null;
        $this->key = $args['key'] ?? null;
        $this->secret = $args['secret'] ?? null;
        $this->logger = $args['logger'] ?? new \Psr\Log\NullLogger;
        $this->timeout = $args['timeout'] ?? 0;
        $this->showWeightUsage = $args['showWeightUsage'] ?? false;
        $this->showHeader = $args['showHeader'] ?? false;
        $this->privateKey = $args['privateKey'] ?? null;
        $this->buildClient($args['httpClient'] ?? null);
    }

    protected function publicRequest($method, $path, array $params = [])
    {
        return $this->processRequest($method, $path, $params);
    }

    protected function signRequest($method, $path, array $params = [])
    {
        $params['timestamp'] = round(microtime(true) * 1000);
        $query = Url::buildQuery($params);

        if ($this->privateKey) {
            openssl_sign($query, $binary_signature, $this->privateKey, OPENSSL_ALGO_SHA256);
            $params['signature'] = base64_encode($binary_signature);
        } else {
            $params['signature'] = hash_hmac('sha256', $query, $this->secret);
        }

        return $this->processRequest($method, $path, $params);
    }

    protected function processRequest($method, $path, $params = [])
    {
        $this->applyRateLimiter($path);

        $logData = [
            'path' => $path,
            'payload' => $params,
            'http_method' => $method,
            'http_headers_sent' => [
                'Content-Type' => 'application/json',
                'X-MBX-APIKEY' => $this->key,
            ],
            'hostname' => gethostname(), // Capture the server's hostname
        ];

        try {
            // Send the request.
            $response = $this->httpRequest->request($method, $this->buildQuery($path, $params));

            // Capture response details.
            $logData['http_response_code'] = $response->getStatusCode();
            $logData['response'] = json_decode($response->getBody(), true);
            $logData['http_headers_returned'] = $response->getHeaders();

            // Extract and record weight from response headers
            $this->updateEndpointWeight($path, $response->getHeaders());

            // Log the request and response.
            $this->logApiRequest($logData);
        } catch (GuzzleClientException $e) {
            $responseBody = $e->getResponse()->getBody()->getContents();

            // Capture exception details in log data.
            $logData['http_response_code'] = $e->getCode();
            $logData['response'] = $e->getMessage();
            $logData['http_headers_returned'] = $e instanceof \GuzzleHttp\Exception\RequestException && $e->getResponse()
                ? $e->getResponse()->getHeaders()
                : null;

            // Log the error response, regardless of whether we skip the exception.
            $this->logApiRequest($logData);

            // Handle 429 Too Many Requests.
            if ($e->getCode() === 429) {
                throw new ApiException('Rate limit exceeded.', 429);
            }

            // Handle HTTP exceptions and skip if necessary.
            if ($this->shouldSkipException($e->getCode(), $responseBody)) {
                // Extract and record weight even if skipping the exception.
                $this->updateEndpointWeight($path, $e->getResponse()->getHeaders());

                // Skip the exception and treat it as a success, return the parsed response.
                return json_decode($responseBody, true);
            }

            // Throw the exception for other cases.
            throw new TryCatchException(throwable: $e);
        }

        // Parse and return the response body.
        return json_decode($response->getBody(), true);
    }

    /**
     * Update the endpoint weight using the response headers from Binance.
     *
     * @param  string  $path
     * @param  array  $headers
     * @return void
     */
    protected function updateEndpointWeight($path, $headers)
    {
        $exchangeId = $this->getExchangeId(); // Hardcoded for Binance in your case

        // Extract weight from the headers
        $weightHeaderKey = 'x-mbx-used-weight-1m'; // Assuming this is the weight header to use
        $weight = isset($headers[$weightHeaderKey]) ? (int) $headers[$weightHeaderKey][0] : 1;

        // Update or create the endpoint weight record.
        EndpointWeight::updateOrCreate(
            ['exchange_id' => $exchangeId, 'endpoint' => $path],
            ['weight' => $weight]
        );
    }

    /**
     * Apply rate limiting based on the IP and endpoint weight.
     *
     * @param  string  $path
     * @return void
     *
     * @throws ApiException
     */
    protected function applyRateLimiter($path)
    {
        $ipAddress = request()->ip(); // Get the client's IP address
        $exchangeId = $this->getExchangeId(); // Hardcoded for Binance in your case

        // Get the current endpoint weight
        $endpointWeight = EndpointWeight::where('exchange_id', $exchangeId)
            ->where('endpoint', $path)
            ->value('weight') ?? 1;

        // Get the current IP weight usage
        $ipRequestWeight = IpRequestWeight::firstOrCreate(
            ['exchange_id' => $exchangeId, 'ip_address' => $ipAddress],
            ['last_reset_at' => now(), 'current_weight' => 0]
        );

        // If last_reset_at is null or more than 1 minute has passed, reset the weight
        if (is_null($ipRequestWeight->last_reset_at) || $ipRequestWeight->last_reset_at->lessThan(now()->subMinute())) {
            $ipRequestWeight->current_weight = 0;
            $ipRequestWeight->last_reset_at = now();
        }

        // Check if the current weight exceeds the configured rate limit.
        $currentWeight = $ipRequestWeight->current_weight + $endpointWeight;
        if ($currentWeight > config('nidavellir.system.api.params.binance.weight_limit')) {
            $ipRequestWeight->is_backed_off = true;
            throw new ApiException('Rate limit exceeded. Please wait before sending more requests.', 429);
        } else {
            $ipRequestWeight->is_backed_off = false;
        }

        // Update the IP weight.
        $ipRequestWeight->current_weight = $currentWeight;
        $ipRequestWeight->save();
    }

    /**
     * Get the exchange ID for Binance.
     */
    protected function getExchangeId(): int
    {
        // Hardcoded for Binance.
        return Exchange::firstWhere('canonical', 'binance')->id;
    }

    protected function buildQuery($path, $params = []): string
    {
        if (count($params) == 0) {
            return $path;
        }

        return $path.'?'.Url::buildQuery($params);
    }

    protected function buildClient($httpRequest)
    {
        $this->httpRequest = $httpRequest ??
        new \GuzzleHttp\Client([
            'base_uri' => $this->baseURL,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-MBX-APIKEY' => $this->key,
                'User-Agent' => 'binance-connect-php',
            ],
            'timeout' => $this->timeout,
        ]);
    }

    /**
     * Check if an exception should be skipped based on configuration.
     * This is because some http errors (like http 400, error code -1202)
     * they shouldn't be treated as errors since binance sends "information"
     * but treats this information as a http error (which is stupid).
     */
    protected function shouldSkipException($httpCode, $responseBody)
    {
        $skipConfig = config('nidavellir.system.api.params.binance.http_errors_to_skip');

        if (isset($skipConfig[$httpCode])) {
            $errorCodesToSkip = $skipConfig[$httpCode];
            $responseArray = json_decode($responseBody, true);

            // Check if any error code in the response matches the list of codes to skip
            if (isset($responseArray['code']) && in_array($responseArray['code'], $errorCodesToSkip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Log API requests to the database using the ApiRequestLog model.
     */
    protected function logApiRequest(array $logData): void
    {
        ApiRequestLog::create([
            'path' => $logData['path'],
            'payload' => $logData['payload'],
            'http_method' => $logData['http_method'],
            'http_headers_sent' => $logData['http_headers_sent'],
            'http_response_code' => $logData['http_response_code'],
            'response' => $logData['response'],
            'http_headers_returned' => $logData['http_headers_returned'],
            'hostname' => $logData['hostname'],
        ]);
    }
}
