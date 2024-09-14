<?php

namespace Nidavellir\Trading;

use Exception;
use Nidavellir\Trading\Models\ExceptionsLog;
use Throwable;

/**
 * Class: NidavellirException
 *
 * This class extends the base Exception class, adding
 * functionality for logging exceptions in the system's
 * custom ExceptionsLog model. It also handles extracting
 * file, line, and trace details, and allows for logging
 * additional model-specific data.
 *
 * Important points:
 * - Captures the file and line where the exception occurred.
 * - Logs custom exceptions in the `exceptions_logs` table.
 * - Supports logging model-related attributes.
 */
class NidavellirException extends Exception
{
    protected $attributes;

    protected $loggable;

    protected $originalException;

    protected $primaryFile;

    protected $primaryLine;

    /**
     * Constructor for NidavellirException.
     *
     * @param  Throwable|null  $originalException  Optional original exception.
     * @param  mixed  $loggable  An optional loggable model.
     * @param  array  $additionalData  Additional data to be logged.
     * @param  string|null  $title  Optional custom title.
     */
    public function __construct(
        ?Throwable $originalException = null, // The $e exception instance
        $loggable = null, // The polymorphic eloquent model.
        array $additionalData = [], // Additional customized data
        ?string $title = null // A friendly exception resumed description
    ) {
        /**
         * Validate that a title or an exception is provided.
         */
        if (! $originalException && ! $title) {
            throw new \InvalidArgumentException(
                'A title must be provided if no exception is passed.'
            );
        }

        $this->originalException = $originalException;
        $this->loggable = $loggable;

        /**
         * Merge loggable model attributes with additional data.
         */
        $this->attributes = array_merge(
            $this->getModelAttributes($loggable),
            $additionalData
        );

        // Capture file and line information from the trace
        $this->capturePrimaryFileAndLine();

        $title = $title ?? (
            $originalException
                ? $originalException->getMessage()
                : 'An error occurred.'
        );

        parent::__construct($title);

        // Log the exception in the system's ExceptionsLog.
        $this->logException();
    }

    /**
     * Captures the primary file and line of the exception.
     */
    protected function capturePrimaryFileAndLine()
    {
        $trace = $this->originalException
            ? $this->originalException->getTrace()
            : $this->getTrace();

        if (! empty($trace) && isset($trace[0]['file'], $trace[0]['line'])) {
            $this->primaryFile = basename($trace[0]['file']);
            $this->primaryLine = $trace[0]['line'];
        } else {
            $this->primaryFile = basename($this->getFile());
            $this->primaryLine = $this->getLine();
        }
    }

    /**
     * Retrieves model attributes for logging.
     *
     * @param  mixed  $loggable  The loggable model.
     * @return array Attributes to be logged.
     */
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

    /**
     * Formats the title to wrap at 80 characters.
     *
     * @param  string  $title  The title to format.
     * @return string The formatted title.
     */
    protected function formatTitle($title)
    {
        return wordwrap($title, 80, "\n               ");
    }

    /**
     * Processes the trace and formats it for logging.
     *
     * @param  array  $trace  The stack trace.
     * @return array Processed trace log.
     */
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

                if ($index >= 10) {
                    break;
                }
            }
        }

        return $traceLog;
    }

    /**
     * Logs the exception details to the system log.
     */
    public function report()
    {
        $trace = $this->originalException
            ? $this->originalException->getTrace()
            : $this->getTrace();

        $traceLog = $this->processTrace($trace);

        /**
         * Build the log message containing exception details.
         */
        $logMessage = implode("\n", [
            "\n",
            '========= '.class_basename(static::class).' =========',
            'Title        : '.$this->formatTitle($this->getMessage()),
            "File         : {$this->primaryFile} [{$this->primaryLine}]",
            'Trace        :',
            implode("\n", $traceLog),
            '=====================================',
        ]);

        \Log::error($logMessage);
    }

    /**
     * Logs the exception into the `exceptions_logs` table.
     */
    public function logException()
    {
        $trace = $this->originalException
            ? $this->originalException->getTrace()
            : $this->getTrace();

        $traceLog = $this->processTrace($trace);

        // Create a new log entry for the exception
        $log = new ExceptionsLog;
        $log->title = $this->getMessage(); // Title field
        $log->error_message = $this->originalException
            ? $this->originalException->getMessage()
            : $this->getMessage(); // Error message field
        $log->exception_class = class_basename(static::class);
        $log->file = $this->primaryFile;
        $log->line = $this->primaryLine;
        $log->attributes = $this->attributes;
        $log->trace = implode("\n", $traceLog);

        if ($this->loggable) {
            $this->loggable->exceptionsLogs()->save($log);
        } else {
            $log->save();
        }
    }
}
