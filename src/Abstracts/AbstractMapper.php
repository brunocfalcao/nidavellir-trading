<?php

namespace Nidavellir\Trading\Abstracts;

use Nidavellir\Trading\Models\Trader;

abstract class AbstractMapper
{
    // The trader that will use the current exchange instance.
    public $trader;

    public function __construct(?Trader $trader = null)
    {
        $this->trader = $trader ?? Auth::user();

        if (! $this->trader) {
            throw new \Exception('No trader detected for the exchange');
        }
    }
}
