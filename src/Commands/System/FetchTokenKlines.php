<?php

namespace Nidavellir\Trading\Commands\System;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Nidavellir\Trading\Models\Symbol;
use PhpZip\ZipFile;

class FetchTokenKlines extends Command
{
    protected $signature = 'trading:fetch-token-klines {candle_size} {start_date} {end_date}';

    protected $description = 'Fetch klines from Binance for multiple tokens over a date range and candle size';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $candleSize = $this->argument('candle_size'); // e.g., '15m'
        $startDate = Carbon::parse($this->argument('start_date')); // e.g., '2024-03-16'
        $endDate = Carbon::parse($this->argument('end_date')); // e.g., '2024-03-18'

        // Fetch the first 200 ranked tokens from the Symbol model
        $tokens = Symbol::take(200)->pluck('token');

        foreach ($tokens as $token) {
            $token = strtoupper($token).'USDT';
            $tableName = "klines_{$candleSize}";

            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, function (Blueprint $table) {
                    $table->id();
                    $table->string('token');
                    $table->bigInteger('open_time')->unique();
                    $table->decimal('open', 20, 10);
                    $table->decimal('high', 20, 10);
                    $table->decimal('low', 20, 10);
                    $table->decimal('close', 20, 10);
                    $table->decimal('volume', 20, 10);
                    $table->bigInteger('close_time');
                    $table->decimal('quote_volume', 20, 10);
                    $table->integer('count');
                    $table->decimal('taker_buy_volume', 20, 10);
                    $table->decimal('taker_buy_quote_volume', 20, 10);
                    $table->decimal('ignore', 20, 10);
                    $table->timestamps();
                });
            }

            $currentDate = $startDate->copy();

            while ($currentDate->lte($endDate)) {
                $date = $currentDate->format('Y-m-d');
                $url = "https://data.binance.vision/data/futures/um/daily/klines/{$token}/{$candleSize}/{$token}-{$candleSize}-{$date}.zip";

                $this->info("Fetching data from: {$url}");

                $response = Http::get($url);

                if ($response->failed()) {
                    $this->error("Failed to download file for date: {$date}");
                    $currentDate->addDay();

                    continue;
                }

                file_put_contents(storage_path("app/{$token}-{$candleSize}-{$date}.zip"), $response->body());

                $this->info('File downloaded successfully. Extracting...');

                $zip = new ZipFile();
                $zip->openFile(storage_path("app/{$token}-{$candleSize}-{$date}.zip"));
                $zip->extractTo(storage_path('app/'));
                $zip->close();

                $csvFile = storage_path("app/{$token}-{$candleSize}-{$date}.csv");
                if (! file_exists($csvFile)) {
                    $this->error("CSV file not found after extraction for date: {$date}");
                    $currentDate->addDay();

                    continue;
                }

                $this->info('CSV file extracted successfully. Processing data...');

                $data = array_map('str_getcsv', file($csvFile));
                $header = array_shift($data); // Remove the header row

                foreach ($data as $row) {
                    $row = array_map(function ($value) {
                        return is_numeric($value) ? (float) $value : $value;
                    }, $row);

                    DB::table($tableName)->updateOrInsert(
                        ['token' => $token, 'open_time' => (int) $row[0]], // Check for existing record
                        [
                            'open' => $row[1],
                            'high' => $row[2],
                            'low' => $row[3],
                            'close' => $row[4],
                            'volume' => $row[5],
                            'close_time' => (int) $row[6], // timestamp
                            'quote_volume' => $row[7],
                            'count' => $row[8],
                            'taker_buy_volume' => $row[9],
                            'taker_buy_quote_volume' => $row[10],
                            'ignore' => $row[11],
                        ]
                    );
                }

                $this->info("Data processing and insertion completed for date: {$date}.");
                $currentDate->addDay();
            }

            $this->info("All data processing and insertion completed for token: {$token}.");
        }

        $this->info('All data processing and insertion completed for all tokens.');

        return 0;
    }
}
