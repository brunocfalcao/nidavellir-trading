<?php

namespace Nidavellir\Trading\Exchanges;

use Nidavellir\Trading\Abstracts\AbstractMapper;

class ExchangeWebsocketMapper
{
    protected $mapper;

    public function __construct(AbstractMapper $mapper)
    {
        $this->mapper = $mapper;
    }

    /**
     * Retrieves mark prices.
     */
    public function markPrices($callback, $eachSecond = true)
    {
        return $this->mapper->markPrices($callback, $eachSecond);
    }
}
