<?php

namespace Nidavellir\Trading\Abstracts;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

abstract class AbstractListener implements ShouldQueue
{
    use InteractsWithQueue;

    public $tries = 3;
}
