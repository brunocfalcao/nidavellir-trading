<?php

namespace Nidavellir\Trading\Jobs\System;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Nidavellir\Trading\Models\System;

class UpsertFearGreedIndexJob implements ShouldQueue
{
    use Batchable,
        Dispatchable,
        InteractsWithQueue,
        Queueable,
        SerializesModels;

    protected $fearGreedIndexUrl = 'https://api.alternative.me/fng/';

    public function __construct()
    {
        // Constructor logic if needed
    }

    public function handle()
    {
        // Fetch the Fear and Greed Index from the API.
        $response = Http::get($this->fearGreedIndexUrl);

        if ($response->successful()) {
            $data = $response->json();

            // Update the System model's fear_greed_index attribute.
            if (isset($data['data'][0]['value'])) {
                $fearGreedIndex = $data['data'][0]['value'];

                // Find the first record in the System table
                $system = System::first();

                if ($system) {
                    // Update existing record
                    $system->update([
                        'fear_greed_index' => $fearGreedIndex,
                        'fear_greed_index_updated_at' => now()]);
                } else {
                    // Create a new record
                    System::create([
                        'fear_greed_index' => $fearGreedIndex,
                        'fear_greed_index_updated_at' => now()]);
                }
            }
        } else {
            // Handle the error (log it, retry, etc.)
            \Log::error('Failed to fetch Fear and Greed Index from API.', ['response' => $response->body()]);
        }
    }
}
