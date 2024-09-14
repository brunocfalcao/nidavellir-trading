<?php

namespace Nidavellir\Trading\Jobs\System;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\NidavellirException;
use Illuminate\Support\Facades\Config;

class DisableSymbolsFromConfigJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        //
    }

    public function handle()
    {
        try {
            $excludedSymbols = Config::get('nidavellir.symbols.excluded.tokens', []);

            if (!empty($excludedSymbols)) {
                Symbol::whereIn('token', $excludedSymbols)
                    ->update(['is_active' => false]);
            }
        } catch (\Throwable $e) {
            throw new NidavellirException($e);
        }
    }
}
