<?php

namespace Nidavellir\Trading\Abstracts;

use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
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
            'mapper_properties' => $this->mapper->properties,
            'exchange_id' => $this->mapper->exchange()->id,
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
