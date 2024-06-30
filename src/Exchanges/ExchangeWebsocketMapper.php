<?php

namespace Nidavellir\Trading\Exchanges;

class ExchangeWebsocketMapper
{
    public $exchange;

    public function __construct(InteractsWithExchangeViaREST $exchangeRESTMapper) {}
}
