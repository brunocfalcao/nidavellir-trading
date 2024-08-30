<?php

namespace Nidavellir\Trading\Abstracts;

use Nidavellir\Trading\Models\Trader;

abstract class AbstractMapper
{
    // Possible trader that will use the current exchange instance.
    protected ?Trader $trader;

    // A possible set of credentials in case we don't have a trader.
    protected array $credentials;

    // Additional properties that are used for api calls.
    public array $properties;

    public function __construct(?Trader $trader = null, ?array $credentials = [])
    {
        $this->trader = $trader;
        $this->credentials = $credentials;

        // $credentials have priority over the $trader.
        if (! is_null($trader) && empty($this->credentials)) {
            $this->credentials = $this->trader->getExchangeCredentials();
        }

        if (empty($this->credentials) && is_null($this->trader)) {
            throw new \Exception('No trader neither credentials defined');
        }
    }
}
