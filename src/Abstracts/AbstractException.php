<?php

namespace App\Exceptions;

use Exception;
use Nidavellir\Trading\Models\ExceptionsLog;

abstract class AbstractException extends Exception
{
    protected $attributes;

    protected $loggable;

    public function __construct($message, $attributes = [], $loggable = null)
    {
        $this->attributes = $this->serializeAttributes($attributes);
        $this->loggable = $loggable;
        parent::__construct($message);

        $this->logException();
    }

    // Serialize attributes, handling objects like Eloquent models
    protected function serializeAttributes($attributes)
    {
        foreach ($attributes as $key => $value) {
            // Check if the value is an object, such as an Eloquent model
            if (is_object($value)) {
                // If it's an Eloquent model, convert to an array
                if (method_exists($value, 'toArray')) {
                    $attributes[$key] = $value->toArray();
                } else {
                    // Otherwise, attempt a simple serialization
                    $attributes[$key] = (string) $value;
                }
            }
        }

        return $attributes;
    }

    public function report()
    {
        // Use the info_multiple helper to log in a more readable format
        $logMessage = implode("\n", [
            '',  // Empty line before the log message
            '========= Exception Occurred =========',
            "Message      : {$this->getMessage()}",
            'File         : '.$this->getFile(),
            'Line number  : '.$this->getLine(),
            'Exception Class: '.class_basename(static::class), // Only the class name
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
        $log->exception_class = class_basename(static::class); // Only the class name
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
