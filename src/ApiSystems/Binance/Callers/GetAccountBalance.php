<?php

namespace Nidavellir\Trading\ApiSystems\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\ApiSystems\Binance\REST\Futures;

class GetAccountBalance extends AbstractCaller
{
    protected string $callerName = 'Get Account Balance';

    public function call()
    {
        $futures = new Futures($this->mapper->connectionDetails());
        $this->result = $futures->getAccountBalance();
    }

    public function parseResult()
    {
        $collection = collect($this->result);
        $usdt = $collection->firstWhere('asset', 'USDT');

        if ($usdt) {
            $balance = (float) $usdt['balance'];
            $crossUnPnl = (float) $usdt['crossUnPnl'];

            if ($crossUnPnl < 0) {
                $balance += $crossUnPnl;
            }

            $this->result = $balance;
        } else {
            $this->result = 0;
        }
    }
}
