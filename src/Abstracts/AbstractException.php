<?php

namespace Nidavellir\Trading\Abstracts;

use Exception;
use Throwable;
use Nidavellir\Trading\Models\ExceptionsLog;

abstract class AbstractException extends Exception
{
    protected $attributes;
    protected $loggable;
    protected $originalException;
    protected $primaryFile;
    protected $primaryLine;

    public function __construct(Throwable $originalException, $loggable = null, array $additionalData = [])
    {
        $this->originalException = $originalException;
        $this->loggable = $loggable;

        // Collect model data if a loggable model is passed
        $modelAttributes = $this->getModelAttributes($loggable);

        // Merge additional data with model attributes (if any)
        $this->attributes = array_merge($modelAttributes, $additionalData);

        // Capture the primary file and line from the trace
        $this->capturePrimaryFileAndLine();

        // Set the message from the original exception
        parent::__construct($originalException->getMessage());

        $this->logException();
    }

    // Capture the first relevant file and line from the trace
    protected function capturePrimaryFileAndLine()
    {
        $trace = $this->originalException->getTrace();

        if (!empty($trace) && isset($trace[0]['file'], $trace[0]['line'])) {
            $this->primaryFile = basename($trace[0]['file']);
            $this->primaryLine = $trace[0]['line'];
        } else {
            // Fallback to the original file and line if no trace is available
            $this->primaryFile = basename($this->getFile());
            $this->primaryLine = $this->getLine();
        }
    }

    // Extract model attributes, specifically the primary key
    protected function getModelAttributes($loggable)
    {
        $attributes = [];

        if (is_object($loggable) && method_exists($loggable, 'getKey')) {
            $primaryKey = $loggable->getKeyName();
            $primaryValue = $loggable->getKey();
            $attributes[$primaryKey] = $primaryValue;
        }

        return $attributes;
    }

    // Format the message to be limited to 80 characters per line
    protected function formatMessage($message)
    {
        $formatted = wordwrap($message, 80, "\n               "); // Indentation for continuation
        return $formatted;
    }

    // Process the trace to filter out internal functions and keep the first 10 valid entries
    protected function processTrace($trace)
    {
        $traceLog = [];
        $index = 0;

        foreach ($trace as $frame) {
            if (isset($frame['file']) && isset($frame['line'])) {
                $file = basename($frame['file']);
                $line = $frame['line'];
                $traceLog[] = "#{$index} {$file}:{$line}";
                $index++;

                // Limit the trace to the first 10 entries
                if ($index >= 10) {
                    break;
                }
            }
        }

        return $traceLog;
    }

    public function report()
    {
        // Use the trace from the original exception if available
        $trace = $this->originalException ? $this->originalException->getTrace() : $this->getTrace();
        $traceLog = $this->processTrace($trace); // Process trace to exclude internal functions

        // Format the log message with aligned colons and no extra newlines
        $logMessage = implode("\n", [
            "\n",  // Ensure there is a newline before the exception log
            "========= ".class_basename(static::class)." =========", // Exception class name at the top
            'Message      : '.$this->formatMessage($this->getMessage()), // Formatted message
            "File         : {$this->primaryFile} [{$this->primaryLine}]", // File and line combined
            'Trace        :',  // Add label for trace
            implode("\n", $traceLog),  // Add the trace entries
            '=====================================',
        ]);

        // Log the entire message as a single log entry
        \Log::error($logMessage);
    }

    public function logException()
    {
        // Use the trace from the original exception if available
        $trace = $this->originalException ? $this->originalException->getTrace() : $this->getTrace();
        $traceLog = $this->processTrace($trace); // Process trace to exclude internal functions

        // Log to the exceptions_log table, including attributes as JSON and the polymorphic model
        $log = new ExceptionsLog;
        $log->message = $this->getMessage();
        $log->exception_class = class_basename(static::class); // Only the class name
        $log->file = $this->primaryFile; // Use the primary file from the trace
        $log->line = $this->primaryLine; // Use the primary line from the trace
        $log->attributes = $this->attributes;
        $log->trace = implode("\n", $traceLog); // Save the first 10 trace entries (skipping internal functions)

        if ($this->loggable) {
            $this->loggable->exceptionsLogs()->save($log);
        } else {
            $log->save();
        }
    }
}
