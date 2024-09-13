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

/**
 * Class: UpsertFearGreedIndexJob
 *
 * This class handles fetching the Fear and Greed Index from an external API
 * and updating the `System` model with the latest value. If the system record
 * does not exist, it creates a new entry in the database.
 *
 * Important points:
 * - Fetches data from the API endpoint.
 * - Updates the `fear_greed_index` in the `System` model.
 * - Logs an error if the API request fails.
 */
class UpsertFearGreedIndexJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * API URL for fetching the Fear and Greed Index.
     */
    protected $fearGreedIndexUrl = 'https://api.alternative.me/fng/';

    /**
     * Constructor for the job.
     */
    public function __construct()
    {
        // Constructor logic if needed
    }

    /**
     * Main function to handle the fetching and updating of the Fear and Greed Index.
     */
    public function handle()
    {
        /**
         * Fetch the Fear and Greed Index from the external API.
         */
        $response = Http::get($this->fearGreedIndexUrl);

        if ($response->successful()) {
            $data = $response->json();

            /**
             * Check if the response contains the required data,
             * and update or create the `System` model accordingly.
             */
            if (isset($data['data'][0]['value'])) {
                $fearGreedIndex = $data['data'][0]['value'];

                // Retrieve the first System record from the database
                $system = System::first();

                if ($system) {
                    /**
                     * Update the existing `System` record with the
                     * new Fear and Greed Index value.
                     */
                    $system->update([
                        'fear_greed_index' => $fearGreedIndex,
                        'fear_greed_index_updated_at' => now(),
                    ]);
                } else {
                    /**
                     * If no record exists, create a new one with the index value.
                     */
                    System::create([
                        'fear_greed_index' => $fearGreedIndex,
                        'fear_greed_index_updated_at' => now(),
                    ]);
                }
            }
        } else {
            /**
             * Log an error if the API request fails.
             */
            \Log::error('Failed to fetch Fear and Greed Index from API.', ['response' => $response->body()]);
        }
    }
}
