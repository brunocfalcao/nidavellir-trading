<?php

namespace Nidavellir\Trading\Exchanges\Binance\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\Exchanges\Binance\REST\Futures;

class GetAccountBalance extends AbstractCaller
{
    protected string $callerName = 'Get Account Balance';

    public function call()
    {
        $futures = new Futures($this->mapper->credentials());
        $this->result = $futures->getAccountBalance();
    }

    public function parseResult()
    {
        dd($this->trader);
        // Remove zero balances, and keep only the others.
        $filteredPortfolio = array_filter($this->result, function ($item) {
            return (float) $item['availableBalance'] !== 0.0;
        });

        // Map the result.
        $result = [];

        foreach ($filteredPortfolio as $item) {
            $this->result[$item['asset']] = (float) $item['availableBalance'];
        }

        dd($this->result);
    }
}
