<?php

namespace Nidavellir\Trading\Jobs\Price;

use Nidavellir\Trading\Abstracts\AbstractJob;

class UpsertPriceJob extends AbstractJob
{
    public $token;

    public $exchangeId;

    public function __construct(string $token, string $exchangeId)
    {
        //
    }

    public function compute() {}
}
