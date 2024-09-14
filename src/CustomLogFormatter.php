<?php

namespace Nidavellir\Trading;

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
        // Iterate over each handler of the logger.
        foreach ($logger->getHandlers() as $handler) {
            // Define the output format to include timestamp, channel, level, and message.
            $output = "[%datetime%] %channel%.%level_name%: %message%\n";

            // Set the date format to match Laravel's default timestamp format.
            $dateFormat = 'Y-m-d H:i:s';

            // Customize the formatter with the defined date and output format.
            $formatter = new LineFormatter($output, $dateFormat, true, true);

            // Apply the customized formatter to the handler.
            $handler->setFormatter($formatter);
        }
    }
}
