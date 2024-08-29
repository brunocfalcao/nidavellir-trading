<?php

namespace Nidavellir\Trading\Exchanges\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exchanges\Binance\REST\Futures;
use Nidavellir\Trading\Models\ApiLog;

class GetExchangeInformation extends AbstractCaller
{
    protected string $callerName = 'Get Exchange Information';

    public function __construct(
        BinanceRESTMapper $mapper,
        array $options = [],
        array $data = [],
        bool $reportSilently = false
    ) {
        $this->mapper = $mapper;
        $this->options = $options;
        $this->data = $data;
        $this->reportSilently = $reportSilently;

        $this->parseRequest();

        $apiLog = ApiLog::create([
            'result' => 'ok',
            'exchange_id' => $this->mapper->exchange()->id,
            'payload' => serialize($this->options),
            'other_data' => serialize($this->data),
            'trader_id' => $this->mapper->trader?->id,
        ]);

        try {
            $this->call();
        } catch (\Exception $e) {
            $apiLog->update([
                'result' => 'error',
                'response' => serialize($this->result),
                'exception' => $e->getMessage().' on file '.$e->getFile().' on line '.$e->getLine().' - '.$e->getCode(),
            ]);

            if (! $this->reportSilently) {
                throw new \Exception(
                    'Api error - '.$this->callerName.' ( '.$this->mapper->exchange()->name.' )'
                );
            }
        }

        /*
        try {
            $this->call();
            $apiLog = ApiLog::create([
                'result' => 'ok',
                'exchange_id' => $this->mapper->exchange()->id,
                'payload' => serialize($this->options),
                'response' => serialize($this->result),
                'other_data' => serialize($this->data),
                'trader_id' => $this->mapper?->trader->id,
                'exception' => $e->getMessage().' on file '.$e->getFile().' on line '.$e->getLine().' - '.$e->getCode(),
            ]);
        } catch (\Exception $e) {
            ApiLog::create([
                'result' => 'error',
                'exchange_id' => $this->mapper->exchange()->id,
                'payload' => serialize($this->options),
                'response' => serialize($this->result),
                'other_data' => serialize($this->data),
                'trader_id' => $this->mapper?->trader->id,
                'exception' => $e->getMessage().' on file '.$e->getFile().' on line '.$e->getLine().' - '.$e->getCode(),
            ]);

            if (! $this->reportSilently) {
                throw new \Exception(
                    'Api error - '.$this->callerName.' ( '.$this->mapper->exchange()->name.' )'
                );
            }
        }
        */

        $this->parseResult();
    }

    public function parseRequest()
    {
        //
    }

    public function call()
    {
        $futures = new Futures($this->mapper->credentials());
        $this->result = $futures->exchangeInfo($this->options)['symbols'];
    }

    /**
     * Returns exchange token information.
     *
     * @return array:
     *
     * Array keys:
     * ['symbol'] (e.g.: "BTC" not "BTCUSDT"!)
     *   ['precision'] => 'price' => XX
     *                    'quantity' => XX
     *                    'quote' => XX
     */
    public function parseResult()
    {
        $sanitizedData = [];

        // --- Transformer operations ---
        foreach ($this->result as $key => $value) {
            $sanitizedData[$value['baseAsset']] = [
                'precision' => [
                    'price' => $value['pricePrecision'],
                    'quantity' => $value['quantityPrecision'],
                    'quote' => $value['quotePrecision'],
                ],
            ];
        }

        $this->result = $sanitizedData;
    }

    public function report()
    {
        //
    }
}
