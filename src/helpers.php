<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Nidavellir\Trading\Models\ExceptionLog;

if (! function_exists('logExceptionChain')) {
    function logExceptionChain($exception)
    {
        while ($exception) {
            $className = get_class($exception);
            $message = $exception->getMessage();
            $file = $exception->getFile();
            $line = $exception->getLine();
            $timestamp = Carbon::now()->format('Y-m-d H:i:s');
            $additionalData = method_exists($exception, 'getAdditionalData')
                ? $exception->getAdditionalData()
                : [];

            $logEntry = "======= {$className} =======\n".
                        "Date / Time: {$timestamp}\n".
                        "Exception Message: {$message}\n".
                        'Filename: '.basename($file)." [{$line}]\n\n";

            if (! empty($additionalData)) {
                $jsonAdditionalData = json_encode($additionalData, JSON_PRETTY_PRINT);
                $logEntry .= "Additional Data: {$jsonAdditionalData}\n\n";
            }

            $stackTrace = array_map(function ($trace) {
                if (isset($trace['file'], $trace['line'])) {
                    return basename($trace['file'])." [{$trace['line']}]";
                }

                return null;
            }, array_slice($exception->getTrace(), 0, 10));

            $stackTrace = array_filter($stackTrace);
            $formattedStackTrace = implode("\n", $stackTrace);

            ExceptionLog::create([
                'exception_message' => $message,
                'filename' => basename($file)." [{$line}]",
                'stack_trace' => json_encode($stackTrace),
                'additional_data' => json_encode($additionalData),
            ]);

            $logEntry .= "Stack Trace:\n{$formattedStackTrace}\n".
                         "======= / {$className} =======\n";

            Log::error($logEntry);

            $exception = $exception->getPrevious();
        }
    }
}
