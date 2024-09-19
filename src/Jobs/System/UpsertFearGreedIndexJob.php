<?php

namespace Nidavellir\Trading\Jobs\System;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\FearAndGreedIndexNotSyncedException;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Models\System;
use Nidavellir\Trading\NidavellirException;
use Throwable;

/**
 * UpsertFearGreedIndexJob handles fetching the Fear and Greed
 * Index from an external API and updating the `System` model
 * with the latest value. If no system record exists, it creates
 * a new entry in the database.
 */
class UpsertFearGreedIndexJob extends AbstractJob
{
    // API URL for fetching the Fear and Greed Index.
    protected $fearGreedIndexUrl = 'https://api.alternative.me/fng/';

    public function __construct()
    {
        $this->logBlock = Str::uuid(); // Generate a UUID block for log entries
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
                } else {
                    // Throw an exception for invalid data
                    throw new FearAndGreedIndexNotSyncedException(
                        message: 'Invalid response data: Missing Fear and Greed Index value',
                        additionalData: [
                            'response_data' => $data]
                    );
                }
            } else {
                // Throw an exception if the API call failed.
                throw new FearAndGreedIndexNotSyncedException(
                    message: 'Failed to fetch Fear and Greed Index from API',
                    additionalData: [
                        'response_status' => $response->status()],
                );
            }
        } catch (Throwable $e) {
            // Throw a NidavellirException if any error occurs during the process.
            throw new TryCatchException(
                throwable: $e,
                additionalData: [
                    'response' => $response,
                ]
            );
        }
    }
}
