<?php

namespace Nidavellir\Trading\Abstracts;

use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Models\ApiLog;

abstract class AbstractCaller
{
    protected AbstractMapper $mapper;

    protected bool $throwSilently;

    protected string $callerName = 'Undefined';

    public $result;

    public function __construct(
        BinanceRESTMapper $mapper
    ) {
        $this->mapper = $mapper;
        $this->throwSilently = false;

        $this->parseRequest();

        $apiLog = ApiLog::create([
            'result' => 'ok',
            'caller_name' => $this->callerName,
            'position_id' => array_key_exists('position', $this->mapper->properties) ? $this->mapper->properties['position']->id : null,
            'order_id' => array_key_exists('order', $this->mapper->properties) ? $this->mapper->properties['order']->id : null,
            'trader_id' => $this->mapper->trader?->id,
            'mapper_properties' => $this->mapper->properties,
            'exchange_id' => $this->mapper->exchange()->id,
        ]);

        try {
            $this->call();
        } catch (\Exception $e) {
            $apiLog->update([
                'result' => 'error',
                'response' => serialize($this->result),
                'exception' => $e->getMessage().' on file '.$e->getFile().' on line '.$e->getLine().' - '.$e->getCode(),
            ]);

            if (! $this->throwSilently) {
                throw new \Exception(
                    'Api error - '.$this->callerName.' ( '.$this->mapper->exchange()->name.' ) - '.$e->getMessage()
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
