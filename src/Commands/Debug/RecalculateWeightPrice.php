<?php

namespace Nidavellir\Trading\Commands\Debug;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Nidavellir\Trading\Jobs\System\RecalculateAvgWeightPrice;
use Nidavellir\Trading\Jobs\System\ScanLimitOrdersForPossibleFills;

class RecalculateWeightPrice extends Command
{
    protected $signature = 'test:weight-price';

    protected $description = 'Recalculates the weight price for the profit order of a given position';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        File::put(storage_path('logs/laravel.log'), '');

        RecalculateAvgWeightPrice::dispatchSync(1);

        //ScanLimitOrdersForPossibleFills::dispatchSync();
    }
}
