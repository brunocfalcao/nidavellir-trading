<?php

namespace Nidavellir\Trading\Exchanges\Binance\REST;

use Binance\APIClient;

class Futures extends APIClient
{
    use Market;
    use Trade;

    public function __construct(array $args = [])
    {
        $args['baseURL'] = $args['url'];
        $args['key'] = $args['key'];
        $args['secret'] = $args['secret'];
        parent::__construct($args);
    }
}
