<?php

namespace Nidavellir\Trading\Abstracts;

use Nidavellir\Trading\Exceptions\TryCatchException;

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

        try {
            $this->call();
        } catch (\TryCatchException $e) {
            if (! $this->throwSilently) {
                throw new TryCatchException(
                    throwable: $e,
                );
            }
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
