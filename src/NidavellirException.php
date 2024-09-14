<?php

namespace Nidavellir\Trading;

use Exception;
use Nidavellir\Trading\Models\ExceptionsLog;
use Throwable;

/**
 * NidavellirException class for handling and logging exceptions
 * with additional model-specific data in the system's custom
 * ExceptionsLog model. It captures the file, line, and trace
 * details.
 */
class NidavellirException extends Exception
{
    // Stores attributes for logging exceptions.
    protected $attributes;

    // Holds the model instance that can be logged.
    protected $loggable;

    // Contains the original exception if available.
    protected $originalException;

    // Stores the file where the exception occurred.
    protected $primaryFile;

    // Stores the line where the exception occurred.
    protected $primaryLine;

    /**
     * Constructor to initialize NidavellirException.
     *
     * This constructor handles the initialization of the
     * exception. It captures relevant file and line details,
     * merges the loggable model's attributes with additional
     * data, and logs the exception.
     */
    public function __construct(
        ?Throwable $originalException = null,
        $loggable = null,
        array $additionalData = [],
        ?string $title = null
    ) {
        // Validate that either a title or an exception is provided.
        if (! $originalException && ! $title) {
            throw new \InvalidArgumentException(
                'A title must be provided if no exception is passed.'
            );
        }

        // Store the original exception and loggable model.
        $this->originalException = $originalException;
        $this->loggable = $loggable;

        // Merge the loggable model's attributes with additional data.
        $this->attributes = array_merge(
            $this->getModelAttributes($loggable),
            $additionalData
        );

        // Capture file and line information from the trace.
        $this->capturePrimaryFileAndLine();

        // Set the exception title or fallback to the exception message.
        $title = $title ?? (
            $originalException
                ? $originalException->getMessage()
                : 'An error occurred.'
        );

        parent::__construct($title);

        // Log the exception.
        $this->logException();
    }

    /**
     * Captures the file and line where the exception occurred.
     *
     * This method extracts the file and line information
     * from the exception trace, or from the current exception
     * if no original exception exists.
     */
    protected function capturePrimaryFileAndLine()
    {
        // Get the trace from the original exception, or use the current trace.
        $trace = $this->originalException
            ? $this->originalException->getTrace()
            : $this->getTrace();

        // Extract the file and line from the first trace frame.
        if (! empty($trace) && isset($trace[0]['file'], $trace[0]['line'])) {
            $this->primaryFile = basename($trace[0]['file']);
            $this->primaryLine = $trace[0]['line'];
        } else {
            // Fallback to the current file and line if no trace is available.
            $this->primaryFile = basename($this->getFile());
            $this->primaryLine = $this->getLine();
        }
    }

    /**
     * Retrieves model attributes for logging.
     *
     * This method extracts key attributes from the loggable
     * model, such as its primary key, to include in the log entry.
     */
    protected function getModelAttributes($loggable)
    {
        $attributes = [];

        // Extract the primary key and its value if the model has a key.
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
     * This method ensures the exception title is formatted
     * for better readability when logged, splitting it into
     * multiple lines if necessary.
     */
    protected function formatTitle($title)
    {
        return wordwrap($title, 80, "\n               ");
    }

    /**
     * Processes and formats the stack trace for logging.
     *
     * This method processes the trace and formats it into a
     * list of entries. It extracts the file and line from
     * each frame, limiting the trace log to 10 entries.
     */
    protected function processTrace($trace)
    {
        $traceLog = [];
        $index = 0;

        // Iterate over the trace and extract the file and line.
        foreach ($trace as $frame) {
            if (isset($frame['file']) && isset($frame['line'])) {
                $file = basename($frame['file']);
                $line = $frame['line'];
                $traceLog[] = "#{$index} {$file}:{$line}";
                $index++;

                // Limit to 10 trace entries.
                if ($index >= 10) {
                    break;
                }
            }
        }

        return $traceLog;
    }

    /**
     * Logs the exception details to the system log.
     *
     * This method constructs a log message containing the
     * exception details, including the title, file, line,
     * and trace information, and logs it.
     */
    public function report()
    {
        // Get the trace from the original exception or the current one.
        $trace = $this->originalException
            ? $this->originalException->getTrace()
            : $this->getTrace();

        // Process and format the trace for logging.
        $traceLog = $this->processTrace($trace);

        // Construct the log message.
        $logMessage = implode("\n", [
            "\n",
            '========= '.class_basename(static::class).' =========',
            'Title        : '.$this->formatTitle($this->getMessage()),
            "File         : {$this->primaryFile} [{$this->primaryLine}]",
            'Trace        :',
            implode("\n", $traceLog),
            '=====================================',
        ]);

        // Log the message.
        \Log::error($logMessage);
    }

    /**
     * Logs the exception into the `exceptions_logs` table.
     *
     * This method inserts a new log entry into the `exceptions_logs`
     * table with details about the exception, including the title,
     * message, file, line, attributes, and trace information.
     */
    public function logException()
    {
        // Get the trace from the original exception or the current one.
        $trace = $this->originalException
            ? $this->originalException->getTrace()
            : $this->getTrace();

        // Process and format the trace for logging.
        $traceLog = $this->processTrace($trace);

        // Create a new log entry for the exception.
        $log = new ExceptionsLog;
        $log->title = $this->getMessage();
        $log->error_message = $this->originalException
            ? $this->originalException->getMessage()
            : $this->getMessage();
        $log->exception_class = class_basename(static::class);
        $log->file = $this->primaryFile;
        $log->line = $this->primaryLine;
        $log->attributes = $this->attributes;
        $log->trace = implode("\n", $traceLog);

        // Save the log entry or associate it with the loggable model.
        if ($this->loggable) {
            $this->loggable->exceptionsLogs()->save($log);
        } else {
            $log->save();
        }
    }

    /**
     * Static method to log and handle fallback exceptions.
     *
     * This method handles uncaught exceptions by logging the
     * exception details and halting execution. It is useful
     * for handling unexpected errors.
     */
    public static function throwFallbackException(Throwable $e)
    {
        // Extract the file and line from the exception.
        $primaryFile = $e->getFile();
        $primaryLine = $e->getLine();
        $exceptionClass = get_class($e); // Start with the default class

        $trace = $e->getTrace();

        // Process trace to find the first user-defined file and class.
        foreach ($trace as $frame) {
            if (isset($frame['file']) && strpos($frame['file'], base_path('app')) !== false) {
                // Look specifically for files under the `app` directory.
                $primaryFile = basename($frame['file']);
                $primaryLine = $frame['line'];

                // Correct the exception class to the class in the user-defined file.
                if (isset($frame['class'])) {
                    $exceptionClass = $frame['class'];
                }

                break;
            }
        }

        // Process the first 10 trace entries for logging.
        $traceLog = [];
        foreach ($trace as $index => $frame) {
            if (isset($frame['file']) && isset($frame['line'])) {
                $file = basename($frame['file']);
                $line = $frame['line'];
                $traceLog[] = "#{$index} {$file}:{$line}";

                if ($index >= 9) {
                    break;
                }
            }
        }

        // Format the log message for the fallback exception.
        $logMessage = implode("\n", [
            "\n",
            '========= '.class_basename($e).' =========',
            'Message      : '.wordwrap($e->getMessage(), 80, "\n               "),
            "File         : {$primaryFile} [{$primaryLine}]",
            'Trace        :',
            implode("\n", $traceLog),
            '=====================================',
        ]);

        // Log the fallback exception message.
        \Log::error($logMessage);

        // Save the exception in the `exceptions_log` table.
        ExceptionsLog::create([
            'title' => 'Fallback Exception',
            'error_message' => $e->getMessage(),
            'exception_class' => $exceptionClass,  // Now correctly reflects where the exception occurred
            'file' => $primaryFile,                // Correct file name from user's code
            'line' => $primaryLine,
            'attributes' => json_encode([]),
            'trace' => implode("\n", $traceLog),
        ]);

        // Halt execution after logging the exception.
        exit(1);
    }
}
