<?php

namespace Nidavellir\Trading\Exchanges\Binance;

use Binance\Exception\ClientException;
use Binance\Exception\ServerException;
use Binance\Util\Url;
use Nidavellir\Trading\Exchanges\IpBalancer;
use Nidavellir\Trading\Models\ApplicationLog;
use Nidavellir\Trading\Models\EndpointWeight;
use Nidavellir\Trading\Models\Exchange;
use Psr\Log\NullLogger;

abstract class BinanceAPIClient
{
    protected $baseURL;

    protected $key;

    protected $secret;

    protected $privateKey;

    protected $logger;

    protected $timeout;

    protected $showWeightUsage;

    protected $showHeader;

    protected $httpRequest = null;

    protected $ipBalancer;

    protected $exchange;

    public function __construct($args = [])
    {
        $this->baseURL = $args['baseURL'] ?? null;
        $this->key = $args['key'] ?? null;
        $this->secret = $args['secret'] ?? null;
        $this->logger = $args['logger'] ?? new NullLogger;
        $this->timeout = $args['timeout'] ?? 0;
        $this->showWeightUsage = $args['showWeightUsage'] ?? false;
        $this->showHeader = $args['showHeader'] ?? false;
        $this->privateKey = $args['privateKey'] ?? null;

        // Hardcoded Binance exchange retrieval
        $this->exchange = Exchange::firstWhere('canonical', 'binance');
        $this->ipBalancer = new IpBalancer($this->exchange);

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
        $ip = $this->ipBalancer->selectIp();
        $maxRetryAttempts = count(config('nidavellir.system.api.ips')) > 1 ? 3 : 1;
        $attempt = 0;

        // Log start of request and selected IP
        ApplicationLog::withActionCanonical('binance.request.ip_selection')
            ->withDescription('Selected IP for request')
            ->withReturnData(['ip' => $ip])
            ->saveLog();

        while ($attempt < $maxRetryAttempts) {
            try {
                $curlOptions = [];

                // Check if IP is 127.0.0.1 (local) and disable CURLOPT_INTERFACE if true
                if ($ip !== '127.0.0.1') {
                    $curlOptions = [
                        'curl' => [
                            CURLOPT_INTERFACE => $ip,
                        ],
                    ];
                } else {
                    ApplicationLog::withActionCanonical('binance.request.local_ip')
                        ->withDescription('Local IP (127.0.0.1) detected, not setting CURLOPT_INTERFACE')
                        ->withReturnData(['ip' => $ip])
                        ->saveLog();
                }

                // Proceed with the request
                $endpointWeight = $this->getEndpointWeight($path);
                $response = $this->httpRequest->request($method, $this->buildQuery($path, $params), $curlOptions);

                $body = json_decode($response->getBody(), true);
                $headers = $response->getHeaders();
                $usedWeight = $headers['X-MBX-USED-WEIGHT-1M'][0] ?? $endpointWeight;

                // Log successful request
                ApplicationLog::withActionCanonical('binance.request.success')
                    ->withDescription('Request to Binance API successful')
                    ->withReturnData(['ip' => $ip, 'weight' => $usedWeight])
                    ->saveLog();

                // Update the IP's weight
                $this->ipBalancer->updateWeightWithExactValue($ip, $usedWeight);

                return $this->formatResponse($response, $body);
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                // Handle rate limit and retry logic
                if ($e->getResponse()->getStatusCode() === 429) {
                    $this->handleRateLimit($ip, $attempt);
                } else {
                    ApplicationLog::withActionCanonical('binance.request.failed')
                        ->withDescription($e->getMessage())
                        ->withReturnData(['ip' => $ip])
                        ->saveLog();
                    throw new ClientException($e->getMessage(), $e);
                }
            } catch (\GuzzleHttp\Exception\ServerException $e) {
                ApplicationLog::withActionCanonical('binance.request.server_error')
                    ->withDescription($e->getMessage())
                    ->withReturnData(['ip' => $ip])
                    ->saveLog();
                throw new ServerException($e->getMessage(), $e);
            }
        }

        // Log if max retries are exceeded
        ApplicationLog::withActionCanonical('binance.request.max_retries_exceeded')
            ->withDescription('Max retry attempts reached')
            ->withReturnData(['ip' => $ip])
            ->saveLog();

        throw new \Exception('Max retry attempts reached');
    }

    protected function getEndpointWeight($endpoint)
    {
        $endpointWeight = EndpointWeight::where('exchange_id', $this->exchange->id)
            ->where('endpoint', $endpoint)
            ->first();

        if (! $endpointWeight) {
            $endpointWeight = EndpointWeight::create([
                'exchange_id' => $this->exchange->id,
                'endpoint' => $endpoint,
                'weight' => 1,
            ]);

            ApplicationLog::withActionCanonical('binance.endpoint.learned')
                ->withDescription('New endpoint weight learned')
                ->withReturnData(['endpoint' => $endpoint, 'weight' => 1])
                ->saveLog();
        }

        return $endpointWeight->weight;
    }

    private function handleRateLimit($ip, &$attempt)
    {
        if (count(config('nidavellir.system.api.ips')) > 1) {
            ApplicationLog::withActionCanonical('binance.ip.backoff')
                ->withDescription('Rate limit exceeded, backing off IP')
                ->withReturnData(['ip' => $ip])
                ->saveLog();

            $this->ipBalancer->backOffIp($ip);
            $ip = $this->ipBalancer->selectNextIp();
            $attempt++;
            sleep(2);
        } else {
            ApplicationLog::withActionCanonical('binance.request.failed')
                ->withDescription('Rate limit exceeded with one IP')
                ->withReturnData(['ip' => $ip])
                ->saveLog();
            throw new \Exception('Rate limit exceeded with one IP');
        }
    }

    private function isIpAvailable($ip)
    {
        // This command checks if the IP is available on the server
        $output = shell_exec("ifconfig | grep '$ip'");

        return ! empty($output);
    }

    private function buildQuery($path, $params = []): string
    {
        if (count($params) == 0) {
            return $path;
        }

        return $path.'?'.Url::buildQuery($params);
    }

    private function buildClient($httpRequest)
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

    private function formatResponse($response, $body)
    {
        if ($this->showWeightUsage) {
            $weights = [];
            foreach ($response->getHeaders() as $name => $value) {
                $name = strtolower($name);
                if (strpos($name, 'x-mbx-used-weight') === 0 ||
                    strpos($name, 'x-mbx-order-count') === 0 ||
                    strpos($name, 'x-sapi-used') === 0) {
                    $weights[$name] = $value;
                }
            }

            return [
                'data' => $body,
                'weight_usage' => $weights,
            ];
        }

        if ($this->showHeader) {
            return [
                'data' => $body,
                'header' => $response->getHeaders(),
            ];
        }

        return $body;
    }
}
