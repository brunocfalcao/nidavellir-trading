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
    protected $attributes;

    protected $loggable;

    protected $originalException;

    protected $primaryFile;

    protected $primaryLine;

    protected $title;

    /**
     * Constructor to initialize NidavellirException.
     */
    public function __construct(
        ?Throwable $originalException = null,
        $loggable = null,
        array $additionalData = [],
        ?string $title = null
    ) {
        // If already a NidavellirException, do not wrap again
        if ($originalException instanceof NidavellirException) {
            throw $originalException;
        }

        if (! $originalException && ! $title) {
            throw new \InvalidArgumentException(
                'A title must be provided if no exception is passed.'
            );
        }

        $this->originalException = $originalException;
        $this->loggable = $loggable;
        $this->attributes = array_merge($this->getModelAttributes($loggable), $additionalData);

        if ($originalException) {
            $this->primaryFile = basename($originalException->getFile());
            $this->primaryLine = $originalException->getLine();
        } else {
            $this->capturePrimaryFileAndLine();
        }

        $this->title = $title ?? ($originalException ? $originalException->getMessage() : 'An error occurred.');

        parent::__construct($this->title);

        $this->logException();
    }

    protected function capturePrimaryFileAndLine()
    {
        $this->primaryFile = basename($this->getFile());
        $this->primaryLine = $this->getLine();
    }

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

    protected function processTrace($trace)
    {
        $traceLog = [];
        foreach (array_slice($trace, 0, 10) as $index => $frame) {
            if (isset($frame['file'], $frame['line'])) {
                $traceLog[] = "#{$index} ".basename($frame['file']).":{$frame['line']}";
            }
        }

        return $traceLog;
    }

    public function logException()
    {
        $trace = $this->originalException ? $this->originalException->getTrace() : $this->getTrace();
        $traceLog = $this->processTrace($trace);

        $log = new ExceptionsLog;
        $log->title = $this->title;
        $log->error_message = $this->originalException ? $this->originalException->getMessage() : $this->getMessage();
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

        $this->logToSystem();
    }

    protected function logToSystem()
    {
        $traceLog = $this->processTrace($this->originalException ? $this->originalException->getTrace() : $this->getTrace());
        $logMessage = implode("\n", [
            "\n",
            '========= '.class_basename(static::class).' =========',
            'Title        : '.$this->formatTitle($this->title),
            'Error Message: '.$this->getMessage(),
            "File         : {$this->primaryFile} [{$this->primaryLine}]",
            'Trace        :',
            implode("\n", $traceLog),
            '=====================================',
        ]);
        \Log::error($logMessage);
    }

    protected function formatTitle($title)
    {
        return wordwrap($title, 80, "\n               ");
    }

    /**
     * static method to log and handle fallback exceptions.
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
                $primaryFile = basename($frame['file']);
                $primaryLine = $frame['line'];

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
            'exception_class' => $exceptionClass,
            'file' => $primaryFile,
            'line' => $primaryLine,
            'attributes' => json_encode([]),
            'trace' => implode("\n", $traceLog),
        ]);

        // Halt execution after logging the exception.
        exit(1);
    }
}
