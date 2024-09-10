<?php

namespace Nidavellir\Trading;

use Monolog\Formatter\LineFormatter;

class CustomLogFormatter
{
    /**
     * Customize the Monolog instance.
     *
     * @param  \Monolog\Logger  $logger
     * @return void
     */
    public function __invoke($logger)
    {
        foreach ($logger->getHandlers() as $handler) {
            // Define the format, ensuring to match the default Laravel timestamp format
            $output = "[%datetime%] %channel%.%level_name%: %message%\n";
            $dateFormat = 'Y-m-d H:i:s'; // Laravel's default timestamp format

            // Customize the formatter with the defined date format
            $formatter = new LineFormatter($output, $dateFormat, true, true);
            $handler->setFormatter($formatter);
        }
    }
}
