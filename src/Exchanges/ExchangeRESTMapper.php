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

    /**
     * Returns the futures portfolio balance, only for the
     * positive ones (availableBalance > 0).
     *
     * @return array:
     *
     * ['ETH' => 0.55, 'USDT' => 553.11]
     */
    public function getAccountBalance()
    {
        return $this->mapper->getAccountBalance();
    }

    // TODO / Testing.
    public function placeSingleOrder(array $options)
    {
        return $this->mapper->newOrder($options);
    }
}
