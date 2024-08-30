<?php

namespace Nidavellir\Trading\Abstracts;

use Illuminate\Support\Str;

abstract class AbstractRESTWrapper
{
    /**
     * The Exchange mapper itself for api calls.
     */
    public $mapper;

    public function __construct(AbstractMapper $mapper)
    {
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
