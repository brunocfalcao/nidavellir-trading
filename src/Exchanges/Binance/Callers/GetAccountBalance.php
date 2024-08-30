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
        $this->result = array_reduce(
            // The original array to reduce.
            $this->result,
            /**
             * The callback function applied to each item in
             * the array.
             */
            function ($carry, $item) {

                // Cast the available balance to a float.
                $balance = (float) $item['availableBalance'];

                /**
                 * If the balance is not zero, add it to the
                 * result array.
                 */
                if ($balance !== 0.0) {

                    /**
                     * Set the asset name as the key and the
                     * balance as the value.
                     */
                    $carry[$item['asset']] = $balance;
                }

                // Return the accumulated result.
                return $carry;
            },
            // Start with an empty array to accumulate results.
            []
        );

        return $this->result;
    }
}
