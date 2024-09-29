<?php

namespace Nidavellir\Trading\Abstracts;

use Illuminate\Support\Str;

abstract class AbstractRESTWrapper
{
    public $mapper;

    public function __construct(AbstractMapper $mapper)
    {
        $this->mapper = $mapper;
    }

    public function __call($name, $arguments)
    {
        if (strpos($name, 'with') === 0) {
            $property = Str::snake(substr($name, 4));
            $this->mapper->properties[$property] = $arguments[0];

            return $this;
        }
    }
}
