<?php

namespace Nidavellir\Trading\Abstracts;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

abstract class AbstractJob implements ShouldQueue
{
    use Batchable,
        Dispatchable,
        InteractsWithQueue,
        Queueable,
        SerializesModels;

    public $tries = 3;

    // Define the backoff time in seconds
    public function backoff()
    {
        return [10];
    }

    // Define the retry time in seconds
    public function retryAfter()
    {
        return 5;
    }
}
