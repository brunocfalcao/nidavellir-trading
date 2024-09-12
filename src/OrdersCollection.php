<?php

namespace Nidavellir\Trading;

class OrdersCollection
{
    protected $orders;

    protected $filteredOrders;

    /**
     * Constructor is private to enforce the use of the static "with" method.
     */
    private function __construct(array $orders)
    {
        $this->orders = $orders;
        $this->filteredOrders = $orders; // Start with the full collection
    }

    /**
     * Static method to initialize the collection with orders.
     *
     * @return static
     */
    public static function with(array $orders)
    {
        return new static($orders);
    }

    /**
     * Filter orders by a specific key and value.
     *
     * @return $this
     */
    public function filter(string $key, string $value)
    {
        $this->filteredOrders = array_filter($this->filteredOrders, function ($order) use ($key, $value) {
            return strtolower($order[$key]) === strtolower($value);
        });

        return $this; // Allow method chaining
    }

    /**
     * Get distinct values for a specific key.
     *
     * @return $this
     */
    public function distinct(string $key)
    {
        // Group by unique values for the key and reduce the array to those unique items
        $uniqueValues = [];
        $this->filteredOrders = array_filter($this->filteredOrders, function ($order) use ($key, &$uniqueValues) {
            if (! in_array($order[$key], $uniqueValues, true)) {
                $uniqueValues[] = $order[$key];

                return true; // Keep unique items
            }

            return false; // Filter out duplicates
        });

        return $this; // Allow method chaining
    }

    /**
     * Sum the values for a specific key.
     */
    public function sum(string $key): float
    {
        return array_reduce($this->filteredOrders, function ($carry, $order) use ($key) {
            return $carry + (float) $order[$key];
        }, 0); // Directly return the sum
    }

    /**
     * Return the filtered array data.
     */
    public function get(): array
    {
        return $this->filteredOrders;
    }
}
