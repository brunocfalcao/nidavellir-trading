<?php

namespace Nidavellir\Trading\Exchanges\Binance;

use Nidavellir\Trading\Abstracts\AbstractMapper;
use Nidavellir\Trading\Exchanges\Binance\REST\Futures;
use Nidavellir\Trading\Models\Exchange;

/**
 * The Mapper translates actions into methods, in a standard
 * way, due to the fact that the exchange api methods can
 * have different name and parameter signatures.
 */
class BinanceMapper extends AbstractMapper
{
    /**
     * Returns the exchange model instance by canonical.
     */
    public function exchange(): Exchange
    {
        return Exchange::firstWhere('canonical', 'binance');
    }

    /**
     * Returns FUTURES credentials.
     */
    public function credentialsForFutures(): array
    {
        return [
            'url' => $this->exchange()->futures_url_prefix,
            'secret' => $this->trader->binance_secret_key,
            'key' => $this->trader->binance_api_key,
        ];
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
    public function getExchangeInformation(array $options = []): array
    {
        $futures = new Futures($this->credentialsForFutures());
        $data = $futures->exchangeInfo($options)['symbols'];

        $sanitizedData = [];

        // --- Transformer operations ---
        foreach ($data as $key => $value) {
            $sanitizedData[$value['baseAsset']] = [
                'precision' => [
                    'price' => $value['pricePrecision'],
                    'quantity' => $value['quantityPrecision'],
                    'quote' => $value['quotePrecision'],
                ],
            ];
        }

        return $sanitizedData;
    }
}
