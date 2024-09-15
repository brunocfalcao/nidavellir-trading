<?php

namespace Nidavellir\Trading\Jobs\System;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Nidavellir\Trading\Models\ApplicationLog;
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

    // UUID block for grouping logs
    private $logBlock;

    /**
     * Constructor for the job.
     */
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
        // Log the start of the job
        ApplicationLog::withActionCanonical('UpsertFearGreedIndexJob.Start')
            ->withDescription('Starting job to update Fear and Greed Index')
            ->withBlock($this->logBlock)
            ->saveLog();

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

                        // Log system update success
                        ApplicationLog::withActionCanonical('UpsertFearGreedIndexJob.UpdateSystem')
                            ->withDescription('Updated system with new Fear and Greed Index')
                            ->withReturnData(['index_value' => $fearGreedIndex])
                            ->withSystemId($system->id)
                            ->withBlock($this->logBlock)
                            ->saveLog();
                    } else {
                        // If no record exists, create a new one with the index value.
                        $system = System::create([
                            'fear_greed_index' => $fearGreedIndex,
                            'fear_greed_index_updated_at' => now(),
                        ]);

                        // Log system creation success
                        ApplicationLog::withActionCanonical('UpsertFearGreedIndexJob.CreateSystem')
                            ->withDescription('Created new system entry with Fear and Greed Index')
                            ->withReturnData(['index_value' => $fearGreedIndex])
                            ->withSystemId($system->id)
                            ->withBlock($this->logBlock)
                            ->saveLog();
                    }
                } else {
                    // Log invalid response data
                    ApplicationLog::withActionCanonical('UpsertFearGreedIndexJob.InvalidData')
                        ->withDescription('Invalid response data: Missing Fear and Greed Index value')
                        ->withReturnData(['response_data' => $data])
                        ->withBlock($this->logBlock)
                        ->saveLog();

                    // Throw an exception for invalid data
                    throw new NidavellirException(
                        title: 'Invalid response data: Missing Fear and Greed Index value',
                        additionalData: ['response_data' => $data],
                        loggable: System::first()
                    );
                }
            } else {
                // Log API failure
                ApplicationLog::withActionCanonical('UpsertFearGreedIndexJob.ApiFailed')
                    ->withDescription('Failed to fetch Fear and Greed Index from API')
                    ->withReturnData(['response_status' => $response->status()])
                    ->withBlock($this->logBlock)
                    ->saveLog();

                // Throw an exception if the API call failed.
                throw new NidavellirException(
                    title: 'Failed to fetch Fear and Greed Index from API',
                    additionalData: ['response_status' => $response->status()],
                    loggable: System::first()
                );
            }

            // Log the successful completion of the job
            ApplicationLog::withActionCanonical('UpsertFearGreedIndexJob.End')
                ->withDescription('Successfully updated Fear and Greed Index')
                ->withBlock($this->logBlock)
                ->saveLog();
        } catch (\Throwable $e) {
            // Log any error that occurs during the job
            ApplicationLog::withActionCanonical('UpsertFearGreedIndexJob.Error')
                ->withDescription('Error occurred while updating Fear and Greed Index')
                ->withReturnData(['error' => $e->getMessage()])
                ->withBlock($this->logBlock)
                ->saveLog();

            // Throw a NidavellirException if any error occurs during the process.
            throw new NidavellirException(
                originalException: $e,
                title: 'Error occurred while updating Fear and Greed Index',
                loggable: System::first()
            );
        }
    }
}
