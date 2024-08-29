<?php

namespace Nidavellir\Trading\Exchanges;

use Nidavellir\Trading\Abstracts\AbstractMapper;
use Nidavellir\Trading\Models\ApiLog;
use Nidavellir\Trading\Models\Exchange;

class ExchangeRESTMapper
{
    protected $mapper;

    protected $data;

    public function __construct(
        AbstractMapper $mapper,
        array $data = []
    ) {
        $this->mapper = $mapper;
        $this->data = $data;
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
    public function getExchangeInformation(array $options = [], array $data = [])
    {
        return $this->mapper->getExchangeInformation($options, $data);
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
        $result = null;

        try {
            $result = $this->mapper->getAccountBalance();

            ApiLog::create([
                'result' => 'ok',
                'exchange_id' => $this->mapper->exchange()->id,
                'response' => json_encode($result, JSON_PRETTY_PRINT),
            ]);

            return $result;
        } catch (\Exception $e) {
            ApiLog::create([
                'result' => 'error',
                'trader_id' => $this->mapper->trader->id,
                'exchange_id' => $this->mapper->exchange()->id,
                'response' => json_encode($result, JSON_PRETTY_PRINT),
                'exception' => json_encode($e, JSON_PRETTY_PRINT),
            ]);

            return false;
        }
    }

    // TODO / Testing.
    public function placeSingleOrder(array $options)
    {
        return $this->mapper->newOrder($options);
    }
}
