<?php

use Illuminate\Support\Facades\Log;
use Nidavellir\Trading\Models\ExceptionsLog;

if (! function_exists('logFallbackException')) {
    function logFallbackException(Throwable $e)
    {
        // Get the stack trace
        $trace = $e->getTrace();
        $traceFile = $e->getFile();
        $traceLine = $e->getLine();

        // If a stack trace exists, get the first relevant frame for file and line
        if (! empty($trace) && isset($trace[0])) {
            $traceFile = $trace[0]['file'] ?? $traceFile;
            $traceLine = $trace[0]['line'] ?? $traceLine;
        }

        // Prepare the last 3 trace lines
        $traceLog = [];
        foreach (array_slice($trace, 0, 3) as $i => $frame) {
            $file = $frame['file'] ?? '[internal function]';
            $line = $frame['line'] ?? '[no line]';
            $traceLog[] = "#{$i} {$file}:{$line}";
        }

        // Format the exception log message with trace details
        $logMessage = implode("\n", [
            '',  // Empty line before the log message
            '========= Exception Occurred =========',
            "Message      : {$e->getMessage()}",
            'File         : '.$traceFile,
            'Line number  : '.$traceLine,
            'Exception Class: '.class_basename(get_class($e)), // Only the class name
            'Trace        : '.implode("\n", $traceLog),  // Add the formatted trace
            '=====================================',
        ]);

        // Log the message using Laravel's default log mechanism
        Log::error($logMessage);

        // Save the exception in the exceptions_log table
        ExceptionsLog::create([
            'message' => $e->getMessage(),
            'exception_class' => class_basename(get_class($e)),
            'file' => $traceFile,
            'line' => $traceLine,
            'attributes' => [], // You can pass additional attributes if needed
        ]);
    }
}
