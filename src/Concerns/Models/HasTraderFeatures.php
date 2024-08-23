<?php

namespace Nidavellir\Trading\Concerns\Models;

trait HasTraderFeatures
{
    public function getAvailableBalance()
    {
        $exchangeRESTMapper = new ExchangeRESTMapper(
            new BinanceMapper($this),
        );

        return $exchangeRESTMapper->getAccountBalance();
    }
}
