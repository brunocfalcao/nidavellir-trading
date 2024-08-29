<?php

namespace Nidavellir\Trading\Abstracts;

abstract class AbstractCaller
{
    protected AbstractMapper $mapper;

    protected array $options;

    protected array $data;

    protected bool $throwSilent;

    protected string $callerName = 'Undefined';

    public $result;
}
