<?php

namespace Nidavellir\Trading\Jobs\Price;

use Illuminate\Support\Facades\Bus;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Jobs\Orders\CancelOrderJob;
use Nidavellir\Trading\Models\Position;

class UpsertPriceJob extends AbstractJob
{
    public $token;

    public $exchangeId;

    public function __construct(string $token, string $exchangeId)
    {
        //
    }

    public function compute()
    {
    }
}
