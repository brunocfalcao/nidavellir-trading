<?php

use Illuminate\Support\Facades\Log;
use Throwable;

if (! function_exists('logFallbackException')) {
    function logFallbackException(Throwable $e)
    {
        // Format the exception log message
        $logMessage = implode("\n", [
            '',  // Empty line before the log message
            "========= Exception Occurred =========",
            "Message      : {$e->getMessage()}",
            'File         : ' . $e->getFile(),
            'Line number  : ' . $e->getLine(),
            'Exception Class: ' . class_basename(get_class($e)), // Only the class name
            "====================================="
        ]);

        // Log the message using Laravel's default log mechanism
        Log::error($logMessage);
    }
}
