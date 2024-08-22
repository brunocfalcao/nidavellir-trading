<?php

namespace Nidavellir\Trading\Jobs\System;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Nidavellir\Trading\Models\Symbol;
use PhpZip\ZipFile;

class FetchTokenKlinesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $symbolId;

    protected $exchangeId;

    protected $candleSize;

    protected $month;

    public function __construct($symbolId, $exchangeId, $candleSize, $month)
    {
        $this->symbolId = $symbolId;
        $this->exchangeId = $exchangeId;
        $this->candleSize = $candleSize;
        $this->month = $month;
    }

    public function handle()
    {
        $tableName = "klines_{$this->candleSize}";

        $symbol = Symbol::findOrFail($this->symbolId);
        $token = strtoupper($symbol->token).'USDT';

        $url = "https://data.binance.vision/data/futures/um/monthly/klines/{$token}/{$this->candleSize}/{$token}-{$this->candleSize}-{$this->month}.zip";

        $response = Http::get($url);

        if ($response->failed()) {
            \Log::error("Failed to download file for month: {$this->month}. URL: {$url}");

            return;
        }

        $zipFilePath = storage_path("app/{$token}-{$this->candleSize}-{$this->month}.zip");
        $csvFilePath = storage_path("app/{$token}-{$this->candleSize}-{$this->month}.csv");

        file_put_contents($zipFilePath, $response->body());

        $zip = new ZipFile;
        $zip->openFile($zipFilePath);
        $zip->extractTo(storage_path('app/'));
        $zip->close();

        if (! file_exists($csvFilePath)) {
            \Log::error("CSV file not found after extraction for month: {$this->month}");

            return;
        }

        $data = array_map('str_getcsv', file($csvFilePath));
        $header = array_shift($data);

        foreach ($data as $row) {
            $exists = DB::table($tableName)
                ->where('exchange_id', $this->exchangeId)
                ->where('symbol_id', $this->symbolId)
                ->where('open_time', (int) $row[0])
                ->exists();

            if (! $exists) {
                DB::table($tableName)->insert([
                    'symbol_id' => $this->symbolId,
                    'open_time' => (int) $row[0],
                    'open' => (float) $row[1],
                    'high' => (float) $row[2],
                    'low' => (float) $row[3],
                    'close' => (float) $row[4],
                    'volume' => (float) $row[5],
                    'close_time' => (int) $row[6],
                    'quote_volume' => (float) $row[7],
                    'count' => (int) $row[8],
                    'taker_buy_volume' => (float) $row[9],
                    'taker_buy_quote_volume' => (float) $row[10],
                    'ignore' => (int) $row[11],
                    'exchange_id' => $this->exchangeId,
                ]);
            }
        }

        // Delete the ZIP and CSV files after processing
        File::delete($zipFilePath, $csvFilePath);
    }
}
