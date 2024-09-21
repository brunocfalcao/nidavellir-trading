<?php

namespace Nidavellir\Trading\ApiSystems\Binance\REST;

use Nidavellir\Trading\ApiSystems\Binance\BinanceAPIClient;

class Futures extends BinanceAPIClient
{
    use Account,
        Market,
        Trade;

    public function __construct(array $args = [])
    {
        parent::__construct($args);
    }
}
