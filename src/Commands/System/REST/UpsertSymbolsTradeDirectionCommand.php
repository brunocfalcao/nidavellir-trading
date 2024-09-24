<?php

namespace Nidavellir\Trading\Commands\System\REST;

use Illuminate\Console\Command;
use Nidavellir\Trading\Jobs\Symbols\UpsertSymbolTradeDirectionJob;

class UpsertSymbolsTradeDirectionCommand extends Command
{
    protected $signature = 'nidavellir:upsert-symbols-direction';

    protected $description = 'Updates the symbol trade directions';

    public function handle()
    {
        foreach (config('nidavellir.symbols.included') as $token) {
            UpsertSymbolTradeDirectionJob::dispatch($token);
        }
    }
}
