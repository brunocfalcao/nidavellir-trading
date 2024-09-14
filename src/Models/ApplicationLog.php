<?php

namespace Nidavellir\Trading\Models;

use Illuminate\Support\Str;
use Nidavellir\Trading\Abstracts\AbstractModel;

/**
 * Example usage:
 *
 * ApplicationLog::withActionCanonical('order.created')
 *     ->withDescription('Order was successfully created')
 *     ->withReturnValue('Success')
 *     ->withReturnData(['order_id' => $order->id])
 *     ->withComments('Created via checkout flow')
 *     ->withTraderId($trader->id)  // Example usage for logging trader ID
 *     ->withSymbolId($symbol->id)  // Example usage for logging symbol ID
 *     ->saveLog();
 */
class ApplicationLog extends AbstractModel
{
    protected $casts = [
        'debug_backtrace' => 'array',
        'return_data' => 'array',
    ];

    /**
     * Magic method to handle dynamic setters like withActionCanonical(), withDescription(), etc.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return $this
     */
    public function __call($method, $parameters)
    {
        if (strpos($method, 'with') === 0) {
            // Convert 'withSomething' to 'something'
            $attribute = lcfirst(substr($method, 4));

            // Convert camelCase to snake_case (e.g., traderId -> trader_id)
            $attribute = Str::snake($attribute);

            // Dynamically set the attribute on the model
            $this->{$attribute} = $parameters[0] ?? null;

            return $this;
        }

        return parent::__call($method, $parameters);
    }

    /**
     * Save the log with dynamic attributes.
     *
     * @return $this
     */
    public function saveLog()
    {
        if (! isset($this->debug_backtrace)) {
            $this->debug_backtrace = debug_backtrace();
        }

        $this->save();

        return $this;
    }
}
