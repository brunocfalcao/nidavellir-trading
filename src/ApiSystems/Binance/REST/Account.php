<?php

namespace Nidavellir\Trading\ApiSystems\Binance\REST;

trait Account
{
    public function getAccountBalance()
    {
        return $this->signRequest('GET', '/fapi/v3/balance');
    }
}
