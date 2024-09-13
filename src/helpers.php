<?php

use Illuminate\Support\Facades\Log;
use Nidavellir\Trading\Models\ExceptionsLog;

if (! function_exists('logFallbackException')) {
    function logFallbackException(Throwable $e)
    {
        // Get the stack trace
        $trace = $e->getTrace();
        $primaryFile = $e->getFile();
        $primaryLine = $e->getLine();

        // If a stack trace exists, get the first relevant frame for file and line
        if (! empty($trace) && isset($trace[0])) {
            $primaryFile = basename($trace[0]['file'] ?? $primaryFile);
            $primaryLine = $trace[0]['line'] ?? $primaryLine;
        }

        // Process the trace to get the first 10 valid entries, excluding internal functions
        $traceLog = [];
        $index = 0;

        foreach ($trace as $frame) {
            if (isset($frame['file']) && isset($frame['line'])) {
                $file = basename($frame['file']);
                $line = $frame['line'];
                $traceLog[] = "#{$index} {$file}:{$line}";
                $index++;

                // Limit to 10 trace entries
                if ($index >= 10) {
                    break;
                }
            }
        }

        // Format the exception log message with aligned colons and processed trace
        $logMessage = implode("\n", [
            "\n",  // Ensure there is a newline before the log message
            '========= '.class_basename(get_class($e)).' =========', // Exception class at the top
            'Message      : '.wordwrap($e->getMessage(), 80, "\n               "), // Formatted message
            "File         : {$primaryFile} [{$primaryLine}]", // File and line combined
            'Trace        :',  // Add label for trace
            implode("\n", $traceLog),  // Add the trace entries
            '=====================================',
        ]);

        // Log the message using Laravel's default log mechanism
        Log::error($logMessage);

        // Save the exception in the exceptions_log table
        ExceptionsLog::create([
            'message' => $e->getMessage(),
            'exception_class' => class_basename(get_class($e)),
            'file' => $primaryFile,
            'line' => $primaryLine,
            'attributes' => [], // Add any additional attributes if necessary
        ]);
    }
}
