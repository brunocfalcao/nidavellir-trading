<?php

namespace Nidavellir\Trading\Exceptions;

use Exception;
use Illuminate\Support\Facades\Log;
use Nidavellir\Trading\Models\ExceptionsLog;

class PositionNotCreatedException extends Exception
{
    protected $attributes;

    protected $loggable;

    public function __construct($message, $attributes = [], $loggable = null)
    {
        $this->attributes = $attributes;
        $this->loggable = $loggable;
        parent::__construct($message);

        // Only log to the exceptions_log table here
        $this->logException();
    }

    public function report()
    {
        // Build a single log message with an empty line before the exception block
        $logMessage = implode("\n", [
            '',  // Adding an empty line before the log message
            '========= Exception Occurred =========',
            "Message      : {$this->getMessage()}",
            'File         : '.$this->getFile(),
            'Line number  : '.$this->getLine(),
            'Exception Class: '.static::class,
            'Attributes   : '.json_encode($this->attributes, JSON_PRETTY_PRINT),
            '=====================================',
        ]);

        // Log the entire message as a single log entry
        \Log::info($logMessage);
    }

    public function logException()
    {
        // Log to the exceptions_log table, including attributes as JSON and the polymorphic model
        $log = new ExceptionsLog;
        $log->message = $this->getMessage();
        $log->exception_class = static::class;
        $log->file = $this->getFile();
        $log->line = $this->getLine();
        $log->attributes = $this->attributes;

        if ($this->loggable) {
            $this->loggable->exceptionsLogs()->save($log);
        } else {
            $log->save();
        }
    }
}
