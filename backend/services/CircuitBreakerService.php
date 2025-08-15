<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Circuit Breaker Service for Automatic Failure Protection
 * 
 * Implements the circuit breaker pattern to prevent cascading failures
 * and provide automatic recovery mechanisms for the rate limiting system.
 */
class CircuitBreakerService
{
    // Circuit breaker states
    const STATE_CLOSED = 'closed';
    const STATE_OPEN = 'open';
    const STATE_HALF_OPEN = 'half_open';
    
    private array $config;
    
    public function __construct()
    {
        $this->config = config('rate_limiting.circuit_breaker', [
            'failure_threshold' => 50,      // Percentage of failures to trip
            'minimum_requests' => 20,       // Minimum requests before considering
            'recovery_timeout' => 300,      // 5 minutes
            'half_open_requests' => 10,     // Requests allowed in half-open state
            'success_threshold' => 5,       // Successes needed to close circuit
        ]);
    }

    /**
     * Check if circuit breaker is open for a given service
     */
    public function isOpen(string $service): bool
    {
        $state = $this->getState($service);
        
        if ($state === self::STATE_OPEN) {
            // Check if recovery timeout has passed
            if ($this->shouldTryRecovery($service)) {
                $this->setState($service, self::STATE_HALF_OPEN);
                $this->resetHalfOpenCounter($service);
                return false; // Allow requests in half-open state
            }
            return true;
        }
        
        return false;
    }

    /**
     * Record a successful request
     */
    public function recordSuccess(string $service): void
    {
        $state = $this->getState($service);
        
        if ($state === self::STATE_HALF_OPEN) {
            $this->incrementHalfOpenSuccess($service);
            
            // Check if we have enough successes to close the circuit
            $successes = $this->getHalfOpenSuccesses($service);
            if ($successes >= $this->config['success_threshold']) {
                $this->setState($service, self::STATE_CLOSED);
                $this->resetMetrics($service);
                Log::info("Circuit breaker closed for service: {$service}");
            }
        } elseif ($state === self::STATE_CLOSED) {
            $this->incrementSuccess($service);
        }
    }

    /**
     * Record a failed request
     */
    public function recordFailure(string $service): void
    {
        $state = $this->getState($service);
        
        if ($state === self::STATE_HALF_OPEN) {
            // Any failure in half-open state opens the circuit
            $this->setState($service, self::STATE_OPEN);
            $this->setOpenTime($service);
            Log::warning("Circuit breaker opened (from half-open) for service: {$service}");
        } elseif ($state === self::STATE_CLOSED) {
            $this->incrementFailure($service);
            
            // Check if we should open the circuit
            if ($this->shouldOpenCircuit($service)) {
                $this->setState($service, self::STATE_OPEN);
                $this->setOpenTime($service);
                Log::warning("Circuit breaker opened for service: {$service}");
            }
        }
    }

    /**
     * Get current state of the circuit breaker
     */
    public function getState(string $service): string
    {
        return Cache::get("circuit_breaker:state:{$service}", self::STATE_CLOSED);
    }

    /**
     * Get recovery time remaining
     */
    public function getRecoveryTime(string $service): int
    {
        $openTime = Cache::get("circuit_breaker:open_time:{$service}");
        if (!$openTime) {
            return 0;
        }
        
        $recoveryTime = $openTime + $this->config['recovery_timeout'];
        return max(0, $recoveryTime - time());
    }

    /**
     * Get circuit breaker metrics
     */
    public function getMetrics(string $service): array
    {
        $windowStart = time() - 300; // 5-minute window
        
        $lua = "
            local service = ARGV[1]
            local window_start = tonumber(ARGV[2])
            
            local success_key = 'circuit_breaker:success:' .. service
            local failure_key = 'circuit_breaker:failure:' .. service
            local state_key = 'circuit_breaker:state:' .. service
            
            -- Remove old entries
            redis.call('ZREMRANGEBYSCORE', success_key, 0, window_start)
            redis.call('ZREMRANGEBYSCORE', failure_key, 0, window_start)
            
            local successes = redis.call('ZCARD', success_key)
            local failures = redis.call('ZCARD', failure_key)
            local state = redis.call('GET', state_key) or 'closed'
            
            return {successes, failures, state}
        ";
        
        $result = Redis::eval($lua, 0, $service, $windowStart);
        
        $successes = (int) $result[0];
        $failures = (int) $result[1];
        $total = $successes + $failures;
        $failureRate = $total > 0 ? ($failures / $total) * 100 : 0;
        
        return [
            'service' => $service,
            'state' => $result[2],
            'successes' => $successes,
            'failures' => $failures,
            'total_requests' => $total,
            'failure_rate' => round($failureRate, 2),
            'recovery_time' => $this->getRecoveryTime($service),
            'last_updated' => time()
        ];
    }

    /**
     * Get metrics for all services
     */
    public function getAllMetrics(): array
    {
        $services = $this->getTrackedServices();
        $metrics = [];
        
        foreach ($services as $service) {
            $metrics[$service] = $this->getMetrics($service);
        }
        
        return $metrics;
    }

    /**
     * Manually open circuit breaker
     */
    public function openCircuit(string $service, string $reason = 'manual'): void
    {
        $this->setState($service, self::STATE_OPEN);
        $this->setOpenTime($service);
        
        Log::warning("Circuit breaker manually opened for service: {$service}", [
            'reason' => $reason
        ]);
    }

    /**
     * Manually close circuit breaker
     */
    public function closeCircuit(string $service, string $reason = 'manual'): void
    {
        $this->setState($service, self::STATE_CLOSED);
        $this->resetMetrics($service);
        
        Log::info("Circuit breaker manually closed for service: {$service}", [
            'reason' => $reason
        ]);
    }

    /**
     * Reset circuit breaker metrics
     */
    public function resetMetrics(string $service): void
    {
        $keys = [
            "circuit_breaker:success:{$service}",
            "circuit_breaker:failure:{$service}",
            "circuit_breaker:half_open_success:{$service}",
            "circuit_breaker:open_time:{$service}"
        ];
        
        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Set circuit breaker state
     */
    private function setState(string $service, string $state): void
    {
        Cache::put("circuit_breaker:state:{$service}", $state, 3600);
        $this->trackService($service);
    }

    /**
     * Check if circuit should be opened
     */
    private function shouldOpenCircuit(string $service): bool
    {
        $metrics = $this->getMetrics($service);
        
        // Need minimum requests before considering
        if ($metrics['total_requests'] < $this->config['minimum_requests']) {
            return false;
        }
        
        // Check failure rate threshold
        return $metrics['failure_rate'] >= $this->config['failure_threshold'];
    }

    /**
     * Check if should try recovery (transition to half-open)
     */
    private function shouldTryRecovery(string $service): bool
    {
        $openTime = Cache::get("circuit_breaker:open_time:{$service}");
        if (!$openTime) {
            return true; // If no open time recorded, allow recovery
        }
        
        return (time() - $openTime) >= $this->config['recovery_timeout'];
    }

    /**
     * Set circuit open time
     */
    private function setOpenTime(string $service): void
    {
        Cache::put("circuit_breaker:open_time:{$service}", time(), 3600);
    }

    /**
     * Increment success counter
     */
    private function incrementSuccess(string $service): void
    {
        $now = time();
        $key = "circuit_breaker:success:{$service}";
        
        Redis::zadd($key, $now, "{$now}:" . uniqid());
        Redis::expire($key, 600); // Keep for 10 minutes
    }

    /**
     * Increment failure counter
     */
    private function incrementFailure(string $service): void
    {
        $now = time();
        $key = "circuit_breaker:failure:{$service}";
        
        Redis::zadd($key, $now, "{$now}:" . uniqid());
        Redis::expire($key, 600); // Keep for 10 minutes
    }

    /**
     * Increment half-open success counter
     */
    private function incrementHalfOpenSuccess(string $service): void
    {
        Cache::increment("circuit_breaker:half_open_success:{$service}", 1);
        Cache::put("circuit_breaker:half_open_success:{$service}", 
                  Cache::get("circuit_breaker:half_open_success:{$service}", 0), 300);
    }

    /**
     * Get half-open success count
     */
    private function getHalfOpenSuccesses(string $service): int
    {
        return Cache::get("circuit_breaker:half_open_success:{$service}", 0);
    }

    /**
     * Reset half-open counter
     */
    private function resetHalfOpenCounter(string $service): void
    {
        Cache::forget("circuit_breaker:half_open_success:{$service}");
    }

    /**
     * Track service for monitoring
     */
    private function trackService(string $service): void
    {
        $services = Cache::get('circuit_breaker:tracked_services', []);
        if (!in_array($service, $services)) {
            $services[] = $service;
            Cache::put('circuit_breaker:tracked_services', $services, 86400); // 24 hours
        }
    }

    /**
     * Get list of tracked services
     */
    private function getTrackedServices(): array
    {
        return Cache::get('circuit_breaker:tracked_services', []);
    }

    /**
     * Check circuit breaker health
     */
    public function healthCheck(): array
    {
        $services = $this->getTrackedServices();
        $health = [
            'status' => 'healthy',
            'services' => [],
            'summary' => [
                'total' => count($services),
                'open' => 0,
                'half_open' => 0,
                'closed' => 0
            ]
        ];
        
        foreach ($services as $service) {
            $metrics = $this->getMetrics($service);
            $health['services'][$service] = $metrics;
            
            // Update summary
            $health['summary'][$metrics['state']]++;
            
            // Set overall status
            if ($metrics['state'] === self::STATE_OPEN && $health['status'] === 'healthy') {
                $health['status'] = 'degraded';
            }
        }
        
        // If more than half the services are open, mark as unhealthy
        if ($health['summary']['open'] > count($services) / 2) {
            $health['status'] = 'unhealthy';
        }
        
        return $health;
    }

    /**
     * Get configuration for dashboard
     */
    public function getConfiguration(): array
    {
        return [
            'failure_threshold' => $this->config['failure_threshold'],
            'minimum_requests' => $this->config['minimum_requests'],
            'recovery_timeout' => $this->config['recovery_timeout'],
            'half_open_requests' => $this->config['half_open_requests'],
            'success_threshold' => $this->config['success_threshold'],
        ];
    }

    /**
     * Update configuration
     */
    public function updateConfiguration(array $config): void
    {
        $validKeys = ['failure_threshold', 'minimum_requests', 'recovery_timeout', 
                     'half_open_requests', 'success_threshold'];
        
        foreach ($config as $key => $value) {
            if (in_array($key, $validKeys) && is_numeric($value) && $value > 0) {
                $this->config[$key] = (int) $value;
            }
        }
        
        Log::info('Circuit breaker configuration updated', $this->config);
    }
}