<?php

namespace Nidavellir\Trading\Abstracts;

use Nidavellir\Trading\Models\ApiLog;
use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;

abstract class AbstractCaller
{
    protected AbstractMapper $mapper;

    protected array $options;

    protected array $data;

    protected bool $throwSilent;

    protected string $callerName = 'Undefined';

    public $result;

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
            'caller_name' => $this->callerName,
            'exchange_id' => $this->mapper->exchange()->id,
            'payload' => serialize($this->options),
            'other_data' => serialize($this->data),
            'position_id' => array_key_exists('position', $this->data) ? $this->data['position']->id : null,
            'order_id' => array_key_exists('order', $this->data) ? $this->data['order']->id : null,
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
        } finally {
            $apiLog->update(['response' => $this->result]);
        }

        $this->parseResult();
    }

    public function parseRequest()
    {
        //
    }

    public function call()
    {
        //
    }

    public function parseResult()
    {
        //
    }
}
