<?php

namespace Nidavellir\Trading\Abstracts;

use Illuminate\Support\Str;

abstract class AbstractRESTWrapper
{
    /**
     * The Exchange mapper itself for api calls.
     */
    protected $mapper;

    /**
     * This properties array will store everything called
     * from the $exchangeRESTMapper->withTrader('1'),
     * so it will have $properties['trader'] = 1;
     */
    protected $properties;

    public function __construct(
        AbstractMapper $mapper,
    ) {
        $this->mapper = $mapper;
    }

    public function __call($name, $arguments)
    {
        if (strpos($name, 'with') === 0) {
            /**
             * Set/append new property key into the mapper
             * properties. Relevant if the mapper needs to
             * be enhanced with properties that will be
             * used on the api call parameters.
             */
            $property = Str::snake(substr($name, 4));
            $this->mapper->properties[$property] = $arguments[0];

            return $this;
        }
    }
}
