<?php

namespace Nidavellir\Trading\Exchanges;

use Nidavellir\Trading\Models\ApplicationLog;
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
            case 'SINGLE-IP':
                return $this->getFixedIp();
            case 'ROUND-ROBIN':
                return $this->getRoundRobinIp();
            case 'LEAST-WEIGHT':
            default:
                return $this->getLeastWeightIp();
        }
    }

    // Tactic 1: Fixed IP (always use the first IP from the config)
    protected function getFixedIp()
    {
        $ips = config('nidavellir.system.api.ips');
        $ip = $ips[0];

        // Log the IP selection
        ApplicationLog::withActionCanonical('ipbalancer.fixed_ip')
            ->withDescription('Fixed IP selected')
            ->withReturnData(['ip' => $ip])
            ->saveLog();

        return $ip;
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

        $ip = $ips[$nextIpIndex];

        // Log the round-robin IP selection
        ApplicationLog::withActionCanonical('ipbalancer.round_robin')
            ->withDescription('Round-robin IP selected')
            ->withReturnData(['ip' => $ip, 'index' => $nextIpIndex])
            ->saveLog();

        return $ip;
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
            $selectedIp = array_key_first($ipWeights); // Return the IP with the least weight

            // Log the least-weight IP selection
            ApplicationLog::withActionCanonical('ipbalancer.least_weight')
                ->withDescription('Least-weight IP selected')
                ->withReturnData(['ip' => $selectedIp, 'weight' => $ipWeights[$selectedIp]])
                ->saveLog();

            return $selectedIp;
        }

        // Log if all IPs have exceeded their weight limit
        ApplicationLog::withActionCanonical('ipbalancer.all_ips_exceeded')
            ->withDescription('All IPs exceeded their rate limit')
            ->saveLog();

        throw new NidavellirException('All IPs have exceeded the rate limit for this exchange.');
    }

    // Backoff logic
    public function backOffIp($ip)
    {
        $ipRecord = IpRequestWeight::where('exchange_id', $this->exchange->id)
            ->where('ip_address', $ip)
            ->first();

        // Set the IP's weight to a high value to exclude it temporarily
        $ipRecord->update([
            'current_weight' => 9999,
            'last_reset_at' => now(),
        ]);

        // Log the IP backoff
        ApplicationLog::withActionCanonical('ipbalancer.ip_backoff')
            ->withDescription('IP backoff applied')
            ->withReturnData(['ip' => $ip])
            ->saveLog();
    }

    // New method to update weight
    public function updateWeightWithExactValue($ip, $weight)
    {
        // Update or create the IP request weight in the database
        IpRequestWeight::updateOrCreate(
            ['exchange_id' => $this->exchange->id, 'ip_address' => $ip],
            ['current_weight' => $weight]
        );

        // Log the weight update
        ApplicationLog::withActionCanonical('ipbalancer.weight_updated')
            ->withDescription('IP weight updated successfully')
            ->withReturnData(['ip' => $ip, 'weight' => $weight])
            ->saveLog();
    }
}
