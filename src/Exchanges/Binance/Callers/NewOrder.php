<?php

namespace Nidavellir\Trading\Exchanges\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\Exchanges\Binance\REST\Futures;

class NewOrder extends AbstractCaller
{
    protected string $callerName = 'New Order';

    public function prepareRequest()
    {
        if (! array_key_exists('timeInForce', $this->mapper->properties)) {
            $this->mapper->properties['timeinforce'] = 'GTC';
        }
    }

    public function call()
    {
        $futures = new Futures($this->mapper->connectionDetails());

        $this->result = $futures->newOrder(
            $this->mapper->properties['symbol'],
            $this->mapper->properties['side'],
            $this->mapper->properties['type'],
            $this->mapper->properties
        );
    }

    /**
     * Returns exchange token information.
     *
     * @return array:
     *
     * Array keys:
     * ['symbol'] (e.g.: "BTC" not "BTCUSDT"!)
     *   ['precision'] => 'price' => XX
     *                    'quantity' => XX
     *                    'quote' => XX
     */
    public function parseResult()
    {
        $sanitizedData = [];

        // --- Transformer operations ---
        foreach ($this->result as $key => $value) {
            $sanitizedData[$value['baseAsset']] = [
                'precision' => [
                    'price' => $value['pricePrecision'],
                    'quantity' => $value['quantityPrecision'],
                    'quote' => $value['quotePrecision'],
                ],
            ];
        }

        $this->result = $sanitizedData;
    }
}
