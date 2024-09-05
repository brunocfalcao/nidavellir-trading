<?php

namespace Nidavellir\Trading\Exchanges\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\Exchanges\Binance\REST\Futures;

class PlaceOrder extends AbstractCaller
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
        $futures = new Futures(
            $this->mapper->connectionDetails()
        );

        $this->result = $futures->newOrder(
            $this->mapper->properties['options']['symbol'],
            $this->mapper->properties['options']['side'],
            $this->mapper->properties['options']['type'],
            $this->mapper->properties['options']
        );
    }
}
