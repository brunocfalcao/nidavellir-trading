<?php

namespace Nidavellir\Trading\Commands\Debug;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Nidavellir\Trading\Jobs\Positions\RollbackPositionJob;

class RollbackPosition extends Command
{
    protected $signature = 'test:rollback-position';

    protected $description = 'Cancels all open orders from a token';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        File::put(storage_path('logs/laravel.log'), '');

        RollbackPositionJob::dispatchSync(1);
    }
}
