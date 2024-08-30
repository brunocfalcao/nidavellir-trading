<?php

namespace Nidavellir\Trading\Exchanges\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\Exchanges\Binance\REST\Futures;

class GetExchangeInformation extends AbstractCaller
{
    protected string $callerName = 'Get Exchange Information';

    public function call()
    {
        $futures = new Futures($this->mapper->credentials());
        $this->result = $futures->exchangeInfo($this->mapper->properties['options'])['symbols'];
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
