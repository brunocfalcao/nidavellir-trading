<?php

namespace Nidavellir\Trading\Exchanges;

use Nidavellir\Trading\Abstracts\AbstractMapper;
use Nidavellir\Trading\Models\Exchange;

class ExchangeRESTMapper
{
    protected $mapper;

    public function __construct(AbstractMapper $mapper)
    {
        $this->mapper = $mapper;
    }

    /**
     * Returns exchange token information.
     *
     * @return array:
     *
     * Array keys:
     * ['symbol']
     *   ['precision'] => 'price' => XX
     *                    'quantity' => XX
     *                    'quote' => XX
     */
    public function getExchangeInformation(array $options = [])
    {
        return $this->mapper->getExchangeInformation($options);
    }
}
