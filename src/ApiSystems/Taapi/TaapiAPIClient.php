<?php

namespace Nidavellir\Trading\ApiSystems\Taapi;

use GuzzleHttp\Exception\ClientException as GuzzleClientException;
use Nidavellir\Trading\Exceptions\ApiException;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Models\ApiRequestLog;

class TaapiAPIClient
{
    private $baseURL;

    private $apiKey;

    private $httpRequest = null;

    public function __construct(array $args)
    {
        $this->baseURL = $args['url'];
        $this->apiKey = $args['api_key'];
        $this->buildClient();
    }

    /**
     * Makes a public request to the Taapi.io API.
     */
    public function publicRequest($method, $path, array $parameters = [])
    {
        return $this->processRequest($method, $path, $parameters);
    }

    protected function processRequest($method, $path, $properties = [])
    {
        $logData = [];

        $logData['path'] = $path;
        $logData['payload'] = $properties;
        $logData['http_method'] = $method;
        $logData['http_headers_sent'] = [
            'Content-Type' => 'application/json',
        ];
        $logData['hostname'] = gethostname();

        try {
            // Include the API key in the parameters as required by Taapi.io
            $properties['secret'] = $this->apiKey;

            // Send the GET request to Taapi.io without complex query building
            $response = $this->httpRequest->request($method, $path, [
                'query' => $properties, // Using 'query' to handle GET parameters properly
            ]);

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
                throw new ApiException('Rate limit exceeded: You have exceeded your request limit (TAAPI.IO rate-limit)!', 429);
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
                'User-Agent' => 'taapi-connect-php',
            ],
        ]);
    }

    /**
     * Logs the API request to the database.
     */
    protected function logApiRequest(array $logData): void
    {
        ApiRequestLog::create($logData);
    }
}
