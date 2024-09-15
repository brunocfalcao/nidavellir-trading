<?php

namespace Nidavellir\Trading\Exchanges\Binance\REST;

use Nidavellir\Trading\Exchanges\Binance\BinanceAPIClient;

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
