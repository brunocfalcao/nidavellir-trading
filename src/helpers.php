<?php

use Illuminate\Support\Facades\Log;
use Nidavellir\Trading\Models\ExceptionsLog;

if (! function_exists('logFallbackException')) {
    function logFallbackException(Throwable $e)
    {
        // Format the exception log message
        $logMessage = implode("\n", [
            '',  // Empty line before the log message
            '========= Exception Occurred =========',
            "Message      : {$e->getMessage()}",
            'File         : '.$e->getFile(),
            'Line number  : '.$e->getLine(),
            'Exception Class: '.class_basename(get_class($e)), // Only the class name
            '=====================================',
        ]);

        // Log the message using Laravel's default log mechanism
        Log::error($logMessage);

        // Save the exception in the exceptions_log table
        ExceptionsLog::create([
            'message' => $e->getMessage(),
            'exception_class' => class_basename(get_class($e)),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'attributes' => [], // You can pass additional attributes if needed
        ]);
    }
}
