<?php

namespace Nidavellir\Trading\Abstracts;

use Exception;
use Nidavellir\Trading\Models\ExceptionsLog;
use Throwable;

abstract class AbstractException extends Exception
{
    protected $attributes;

    protected $loggable;

    protected $originalException;

    public function __construct(Throwable $originalException, $loggable = null, array $additionalData = [])
    {
        $this->originalException = $originalException;
        $this->loggable = $loggable;

        // Collect model data if a loggable model is passed
        $modelAttributes = $this->getModelAttributes($loggable);

        // Merge additional data with model attributes (if any)
        $this->attributes = array_merge($modelAttributes, $additionalData);

        // Set the message from the original exception
        parent::__construct($originalException->getMessage());

        $this->logException();
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

    public function report()
    {
        // Use the trace from the original exception if available
        $trace = $this->originalException ? $this->originalException->getTrace() : $this->getTrace();
        $traceLog = [];

        // Dump all entries in the trace with file and line info
        foreach ($trace as $i => $frame) {
            $file = isset($frame['file']) ? basename($frame['file']) : '[internal function]';
            $line = $frame['line'] ?? '[no line]';
            $traceLog[] = "#{$i} {$file}:{$line}";
        }

        // Format the log message
        $logMessage = implode("\n", [
            '',
            '========= Exception Occurred =========',
            "Message      : {$this->getMessage()}",
            'File         : '.basename($this->getFile()), // Use basename to avoid full path
            'Line number  : '.$this->getLine(),
            'Exception Class: '.class_basename(static::class),
            'Attributes   : '.json_encode($this->attributes, JSON_PRETTY_PRINT),
            'Trace        : '.implode("\n", $traceLog),  // Add the full trace
            '=====================================',
        ]);

        // Log the entire message as a single log entry
        \Log::error($logMessage);
    }

    public function logException()
    {
        // Use the trace from the original exception if available
        $trace = $this->originalException ? $this->originalException->getTrace() : $this->getTrace();
        $traceLog = [];

        // Dump all entries in the trace with file and line info
        foreach ($trace as $i => $frame) {
            $file = isset($frame['file']) ? basename($frame['file']) : '[internal function]';
            $line = $frame['line'] ?? '[no line]';
            $traceLog[] = "#{$i} {$file}:{$line}";
        }

        // Log to the exceptions_log table, including attributes as JSON and the polymorphic model
        $log = new ExceptionsLog;
        $log->message = $this->getMessage();
        $log->exception_class = class_basename(static::class); // Only the class name
        $log->file = basename($this->getFile()); // Use basename to avoid full path
        $log->line = $this->getLine();
        $log->attributes = $this->attributes;
        $log->trace = implode("\n", $traceLog); // Save the full trace

        if ($this->loggable) {
            $this->loggable->exceptionsLogs()->save($log);
        } else {
            $log->save();
        }
    }
}
