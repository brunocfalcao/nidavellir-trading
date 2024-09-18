<?php

namespace Nidavellir\Trading\Exchanges;

use Nidavellir\Trading\Models\Exchange;
use Nidavellir\Trading\Models\IpRequestWeight;

class IpBalancer
{
    protected $exchange;

    protected $weightLimit;

    public function __construct(Exchange $exchange)
    {
        $this->exchange = $exchange;
        $this->weightLimit = config('nidavellir.system.api.params.binance.weight_limit'); // Grab the weight limit from config
    }

    public function selectIp()
    {
        $balancerType = config('nidavellir.system.api.ip_balancer_type');

        switch ($balancerType) {
            case 'single-ip':
                return $this->getFixedIp();
            case 'round-robin':
                return $this->getRoundRobinIp();
            case 'least-weight':
            default:
                return $this->getLeastWeightIp();
        }
    }

    // Tactic 1: Fixed IP (always use the first IP from the config)
    protected function getFixedIp()
    {
        $ips = config('nidavellir.system.api.ips');
        return $ips[0];
    }

    // Tactic 2: Round-robin IP (cycle through IPs)
    protected function getRoundRobinIp()
    {
        $ips = config('nidavellir.system.api.ips'); // Get all IPs from config
        $lastUsedIpIndex = cache('last_used_ip_index_'.$this->exchange->id, 0); // Get the last used index from cache, default to 0

        // Calculate the next index (wrap around if necessary)
        $nextIpIndex = ($lastUsedIpIndex + 1) % count($ips);

        // Cache the new index
        cache(['last_used_ip_index_'.$this->exchange->id => $nextIpIndex]);

        return $ips[$nextIpIndex];
    }

    // Tactic 3: Least weight IP (the IP with the least weight)
    protected function getLeastWeightIp()
    {
        $ips = config('nidavellir.system.api.ips'); // Get all IPs from config
        $ipWeights = [];

        // Loop through all IPs and get their current weight
        foreach ($ips as $ip) {
            $currentWeight = IpRequestWeight::where('exchange_id', $this->exchange->id)
                ->where('ip_address', $ip)
                ->value('current_weight') ?? 0; // Default to 0 if no weight is found

            // Only consider IPs under the weight limit
            if ($currentWeight < $this->weightLimit) {
                $ipWeights[$ip] = $currentWeight;
            }
        }

        // Return the IP with the least weight
        if (! empty($ipWeights)) {
            asort($ipWeights); // Sort by weight
            return array_key_first($ipWeights); // Return the IP with the least weight
        }

        throw new NidavellirException('All IPs have exceeded the rate limit for this exchange.');
    }

    // Backoff logic: Increase the weight temporarily when the rate limit is hit
    public function backOffIp($ip)
    {
        $ipRecord = IpRequestWeight::where('exchange_id', $this->exchange->id)
            ->where('ip_address', $ip)
            ->firstOrFail(); // Ensure that the IP exists

        // Increase the current weight to exclude it temporarily
        $ipRecord->current_weight += 100; // Arbitrary increase for backoff
        $ipRecord->save();
    }

    public function updateWeightWithExactValue($ip, $weight)
    {
        // Retrieve the existing record for the IP or create a new one if it doesn't exist
        $ipRecord = IpRequestWeight::firstOrCreate(
            ['exchange_id' => $this->exchange->id, 'ip_address' => $ip],
            ['current_weight' => 0] // Initialize current_weight to 0 if it's a new record
        );

        // Get the max requests per minute from the config
        $rateLimitPerMinute = config('nidavellir.system.api.params.binance.weight_limit'); // Adjust the config path if needed

        // Get the current time and last updated time
        $lastUpdatedAt = $ipRecord->updated_at;
        $currentTime = now();

        // Reset the weight if the current request is in a different minute than the last update
        if ($lastUpdatedAt->format('Y-m-d H:i') !== $currentTime->format('Y-m-d H:i')) {
            $ipRecord->current_weight = $weight;  // Reset to the current weight
        } else {
            // Otherwise, sum the weight with the existing value
            $ipRecord->current_weight += $weight;

            // Optional: If the weight exceeds the rate limit, you can log it or take action
            if ($ipRecord->current_weight > $rateLimitPerMinute) {
                // Take action if needed (e.g., trigger alerts or logging)
            }
        }

        // Save the updated record (updated_at will be automatically set)
        $ipRecord->save();
    }
}
