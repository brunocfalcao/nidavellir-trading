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
use Nidavellir\Trading\NidavellirException;

/**
 * UpsertFearGreedIndexJob handles fetching the Fear and Greed
 * Index from an external API and updating the `System` model
 * with the latest value. If no system record exists, it creates
 * a new entry in the database.
 */
class UpsertFearGreedIndexJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // API URL for fetching the Fear and Greed Index.
    protected $fearGreedIndexUrl = 'https://api.alternative.me/fng/';

    /**
     * Constructor for the job.
     */
    public function __construct()
    {
        // Constructor logic can be added here if needed.
    }

    /**
     * Main function to handle fetching and updating the
     * Fear and Greed Index in the `System` model.
     */
    public function handle()
    {
        try {
            // Fetch the Fear and Greed Index from the external API.
            $response = Http::get($this->fearGreedIndexUrl);

            // Check if the API response was successful.
            if ($response->successful()) {
                $data = $response->json();

                // Check if the required Fear and Greed Index value is present in the response.
                if (isset($data['data'][0]['value'])) {
                    $fearGreedIndex = $data['data'][0]['value'];

                    // Retrieve the first system record from the database.
                    $system = System::first();

                    if ($system) {
                        // Update the existing System record with the new index value.
                        $system->update([
                            'fear_greed_index' => $fearGreedIndex,
                            'fear_greed_index_updated_at' => now(),
                        ]);
                    } else {
                        // If no record exists, create a new one with the index value.
                        $system = System::create([
                            'fear_greed_index' => $fearGreedIndex,
                            'fear_greed_index_updated_at' => now(),
                        ]);
                    }

                    // Loggable model for exception handling if needed.
                    $loggable = $system;
                } else {
                    // Throw an exception if the response data is invalid or missing the required value.
                    throw new NidavellirException(
                        title: 'Invalid response data: Missing Fear and Greed Index value',
                        additionalData: ['response_data' => $data],
                        loggable: System::first()
                    );
                }
            } else {
                // Throw an exception if the API call failed.
                throw new NidavellirException(
                    title: 'Failed to fetch Fear and Greed Index from API',
                    additionalData: ['response_status' => $response->status()],
                    loggable: System::first()
                );
            }
        } catch (\Throwable $e) {
            // Throw a NidavellirException if any error occurs during the process.
            throw new NidavellirException(
                originalException: $e,
                title: 'Error occurred while updating Fear and Greed Index',
                loggable: System::first()
            );
        }
    }
}
