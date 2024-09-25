<?php

namespace Nidavellir\Trading\Abstracts;

use Nidavellir\Trading\Models\Trader;

abstract class AbstractMapper
{
    // Possible trader that will use the current exchange instance.
    public ?Trader $trader;

    // A possible set of credentials in case we don't have a trader.
    protected array $credentials;

    // Additional properties that are used for api calls.
    public array $properties = [];

    /**
     * If we need to pass additional data to the constructor
     * (e.g.: taapi exchange canonical).
     */
    public array $additionalData = [];

    public function __construct(?Trader $trader = null, ?array $credentials = [], ?array $additionalData = [])
    {
        $this->trader = $trader;
        $this->credentials = $credentials;
        $this->additionalData = $additionalData;

        // Necessary for api calls that needs an "options".
        $this->properties['options'] = [];

        // $credentials have priority over the $trader.
        if (! is_null($trader) && empty($this->credentials)) {
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
