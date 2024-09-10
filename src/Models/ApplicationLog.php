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
 *     ->withLoggable($order)  // Polymorphic relationship (e.g., Order)
 *     ->saveLog();
 */
class ApplicationLog extends AbstractModel
{
    // Casting debug_backtrace as array
    protected $casts = [
        'debug_backtrace' => 'array',  // Automatically cast to array when retrieving/saving
        'return_data' => 'array',
    ];

    /**
     * Polymorphic relationship: An ApplicationLog can belong to any model.
     */
    public function loggable()
    {
        return $this->morphTo();
    }

    /**
     * Magic method to handle dynamic setters like withActionCanonical(), withDescription(), etc.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return $this
     */
    public function __call($method, $parameters)
    {
        // Check if the method starts with 'with'
        if (strpos($method, 'with') === 0) {
            // Convert 'withSomething' to 'something'
            $attribute = lcfirst(substr($method, 4));

            // Convert camelCase to snake_case (e.g., actionCanonical -> action_canonical)
            $attribute = Str::snake($attribute);

            // Handle the loggable (polymorphic) relationship separately
            if ($attribute === 'loggable' && isset($parameters[0])) {
                $loggable = $parameters[0];
                $this->loggable_id = $loggable->id;
                $this->loggable_type = get_class($loggable);

                return $this;
            }

            // Dynamically set the attribute on the model
            $this->{$attribute} = $parameters[0] ?? null;

            return $this;
        }

        // If method is not dynamic, fall back to parent behavior
        return parent::__call($method, $parameters);
    }

    /**
     * Save the log with dynamic attributes.
     *
     * @return $this
     */
    public function saveLog()
    {
        // Automatically add debug backtrace if it's not set
        if (! isset($this->debug_backtrace)) {
            $this->debug_backtrace = debug_backtrace();
        }

        // Save the current instance
        $this->save();

        return $this;
    }
}
