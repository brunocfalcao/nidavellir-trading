<?php

namespace Nidavellir\Trading\ApiSystems\Binance\REST;

trait Account
{
    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Place-Multiple-Orders
    public function getAccountBalance()
    {
        return $this->signRequest(
            'GET',
            '/fapi/v3/balance'
        );
    }
}
