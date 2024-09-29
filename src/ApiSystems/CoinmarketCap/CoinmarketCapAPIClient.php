<?php

namespace Nidavellir\Trading\ApiSystems\CoinmarketCap;

use GuzzleHttp\Exception\ClientException as GuzzleClientException;
use Nidavellir\Trading\Exceptions\ApiException;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Models\ApiRequestLog;

class CoinmarketCapAPIClient
{
    private string $baseURL;

    private string $apiKey;

    private $httpRequest = null;

    public function __construct(array $args)
    {
        $this->baseURL = $args['url'];
        $this->apiKey = $args['api_key'];
        $this->buildClient();
    }

    public function publicRequest($method, $path, array $parameters = [])
    {
        return $this->processRequest($method, $path, $parameters);
    }

    protected function processRequest($method, $path, $properties = [])
    {
        $logData = [
            'path' => $path,
            'payload' => $properties,
            'http_method' => $method,
            'http_headers_sent' => [
                'X-CMC_PRO_API_KEY' => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'hostname' => gethostname(),
        ];

        try {
            $response = $this->httpRequest->request($method, $path, [
                'query' => $properties['options'],
                'headers' => ['X-CMC_PRO_API_KEY' => $this->apiKey],
            ]);

            $logData['http_response_code'] = $response->getStatusCode();
            $logData['response'] = json_decode($response->getBody(), true);
            $logData['http_headers_returned'] = $response->getHeaders();

            $this->logApiRequest($logData);
        } catch (GuzzleClientException $e) {
            $responseBody = $e->getResponse()->getBody()->getContents();

            $logData['http_response_code'] = $e->getCode();
            $logData['response'] = $responseBody;
            $logData['http_headers_returned'] = $e instanceof \GuzzleHttp\Exception\RequestException && $e->getResponse()
                ? $e->getResponse()->getHeaders()
                : null;

            $this->logApiRequest($logData);

            if ($e->getCode() === 429) {
                throw new ApiException('Rate limit exceeded: You have exceeded your request limit (CoinMarketCap rate-limit)!', 429);
            }

            throw new TryCatchException(throwable: $e);
        }

        return json_decode($response->getBody(), true);
    }

    protected function buildClient()
    {
        $this->httpRequest = new \GuzzleHttp\Client([
            'base_uri' => $this->baseURL,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept-Encoding' => 'application/json',
                'User-Agent' => 'coinmarketcap-connect-php',
            ],
        ]);
    }

    protected function logApiRequest(array $logData)
    {
        ApiRequestLog::create($logData);
    }
}
