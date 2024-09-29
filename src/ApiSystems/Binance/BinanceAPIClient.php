<?php

namespace Nidavellir\Trading\ApiSystems\Binance;

use Binance\Util\Url;
use GuzzleHttp\Exception\ClientException as GuzzleClientException;
use Nidavellir\Trading\Exceptions\ApiException;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Models\ApiRequestLog;
use Nidavellir\Trading\Models\ApiSystem;

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

    protected function publicRequest($method, $path, array $properties = [])
    {
        return $this->processRequest($method, $path, $properties);
    }

    protected function signRequest($method, $path, array $properties = [])
    {
        $properties['options']['timestamp'] = round(microtime(true) * 1000);
        $query = Url::buildQuery($properties['options']);

        if ($this->privateKey) {
            openssl_sign($query, $binary_signature, $this->privateKey, OPENSSL_ALGO_SHA256);
            $properties['options']['signature'] = base64_encode($binary_signature);
        } else {
            $properties['options']['signature'] = hash_hmac('sha256', $query, $this->secret);
        }

        return $this->processRequest($method, $path, $properties);
    }

    protected function processRequest($method, $path, $properties = [])
    {
        $logData = [];

        if (isset($properties['loggable'])) {
            $model = $properties['loggable'];
            $logData['loggable_id'] = $model->id;
            $logData['loggable_type'] = get_class($model);
        }

        $properties = $properties['options'] ?? $properties;
        $logData = array_merge($logData, [
            'path' => $path,
            'payload' => $properties,
            'http_method' => $method,
            'http_headers_sent' => [
                'Content-Type' => 'application/json',
                'X-MBX-APIKEY' => $this->key,
            ],
            'hostname' => gethostname(),
        ]);

        try {
            $response = $this->httpRequest->request($method, $this->buildQuery($path, $properties));
            $logData['http_response_code'] = $response->getStatusCode();
            $logData['response'] = json_decode($response->getBody(), true);
            $logData['http_headers_returned'] = $response->getHeaders();
            $this->logApiRequest($logData);
        } catch (GuzzleClientException $e) {
            $responseBody = $e->getResponse()->getBody()->getContents();
            $logData['http_response_code'] = $e->getCode();
            $logData['response'] = $e->getMessage();
            $logData['http_headers_returned'] = $e instanceof \GuzzleHttp\Exception\RequestException && $e->getResponse()
                ? $e->getResponse()->getHeaders()
                : null;

            $this->logApiRequest($logData);

            if ($e->getCode() === 429) {
                throw new ApiException('Rate limit exceeded.', 429);
            }

            if ($this->shouldSkipException($e->getCode(), $responseBody)) {
                return json_decode($responseBody, true);
            }

            throw new TryCatchException(throwable: $e);
        }

        return json_decode($response->getBody(), true);
    }

    protected function getExchangeId()
    {
        return ApiSystem::firstWhere('canonical', 'binance')->id;
    }

    protected function buildQuery($path, $properties = [])
    {
        return count($properties) === 0 ? $path : $path.'?'.Url::buildQuery($properties);
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

    protected function shouldSkipException($httpCode, $responseBody)
    {
        $skipConfig = config('nidavellir.system.api.params.binance.http_errors_to_skip');

        if (isset($skipConfig[$httpCode])) {
            $errorCodesToSkip = $skipConfig[$httpCode];
            $responseArray = json_decode($responseBody, true);

            if (isset($responseArray['code']) && in_array($responseArray['code'], $errorCodesToSkip)) {
                return true;
            }
        }

        return false;
    }

    protected function logApiRequest(array $logData)
    {
        ApiRequestLog::create($logData);
    }
}
