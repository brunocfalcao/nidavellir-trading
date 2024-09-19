<?php

namespace Nidavellir\Trading\Logging;

use Monolog\Formatter\LineFormatter;

/**
 * CustomLogFormatter is responsible for customizing the
 * Monolog logger's output format to match Laravel's default
 * timestamp format and other specific log formatting needs.
 */
class CustomLogFormatter
{
    /**
     * Customizes the Monolog instance by setting a custom
     * formatter on all handlers. This ensures that the
     * logger outputs messages with the desired format
     * and timestamp.
     */
    public function __invoke($logger)
    {
        foreach ($logger->getHandlers() as $handler) {
            // Update this output string to remove the Laravel prefix.
            $output = "%message%\n";
            $dateFormat = 'Y-m-d H:i:s';

            $formatter = new LineFormatter($output, $dateFormat, true, true);
            $handler->setFormatter($formatter);
        }
    }
}
