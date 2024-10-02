<?php

namespace Nidavellir\Trading\Commands\Debug;

use Illuminate\Console\Command;
use Nidavellir\Trading\Models\Trader;

class PositionInformation extends Command
{
    protected $signature = 'nidavellir:position-information
                                {--token= : Specific for a token (optional)}';

    protected $description = 'Displays position information';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        if ($this->option('token')) {
            $options = ['symbol' => $this->option.'USDT'];
        } else {
            $options = [];
        }

        $positions = collect(Trader::find(1)
            ->withRESTApi()
            ->withOptions($options)
            ->getPositions())
            ->where('positionAmt', '>', 0);

        dd($positions);
    }
}
