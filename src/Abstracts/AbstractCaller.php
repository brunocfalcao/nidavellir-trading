<?php

namespace Nidavellir\Trading\Abstracts;

use Nidavellir\Trading\Models\ApiLog;
use Nidavellir\Trading\NidavellirException;

abstract class AbstractCaller
{
    protected AbstractMapper $mapper;

    protected bool $throwSilently;

    protected string $callerName = 'Undefined';

    public $result;

    public function __construct(
        AbstractMapper $mapper,
        $throwSilently = false
    ) {
        $this->mapper = $mapper;
        $this->throwSilently = $throwSilently;

        $this->prepareRequest();

        $apiLog = ApiLog::create([
            'result' => 'ok',
            'caller_name' => $this->callerName,
            'position_id' => array_key_exists('position', $this->mapper->properties) ? $this->mapper->properties['position']->id : null,
            'order_id' => array_key_exists('order', $this->mapper->properties) ? $this->mapper->properties['order']->id : null,
            'exchange_symbol_id' => array_key_exists('exchange_symbol', $this->mapper->properties) ? $this->mapper->properties['exchange_symbol']->id : null,
            'trader_id' => array_key_exists('trader', $this->mapper->properties) ? $this->mapper->properties['trader']->id : null,
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
                throw new NidavellirException(
                    originalException: $e,
                    loggable: $apiLog, // Use the ApiLog model as loggable
                    title: 'API Call Exception',
                    additionalData: ['result' => serialize($this->result)]
                );
            }
        } finally {
            $apiLog->update(['response' => $this->result]);
        }

        $this->parseResult();
    }

    public function prepareRequest()
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
