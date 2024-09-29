<?php

namespace Nidavellir\Trading\Abstracts;

use Nidavellir\Trading\Models\Trader;

abstract class AbstractMapper
{
    public ?Trader $trader;
    protected array $credentials;
    public array $properties = [];
    public array $additionalData = [];

    public function __construct(?Trader $trader = null, ?array $credentials = [], ?array $additionalData = [])
    {
        $this->trader = $trader;
        $this->credentials = $credentials;
        $this->additionalData = $additionalData;

        $this->properties['options'] = [];

        if (!is_null($trader) && empty($this->credentials)) {
            $this->credentials = $this->trader->getExchangeCredentials();
        }

        if (empty($this->credentials) && is_null($this->trader)) {
            throw new \Exception('No trader neither credentials defined');
        }
    }

    public function withLoggable($model)
    {
        $this->properties['loggable'] = $model;
        return $this;
    }

    public function exchange()
    {
    }

    public function connectionDetails()
    {
    }
}
