<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\SecurityAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;

/**
 * Enhanced Rate Limiting Service with Tiered Limits and Enterprise Features
 * 
 * Provides advanced rate limiting with multi-tier support, circuit breakers,
 * queue management, and comprehensive threat detection.
 */
class EnhancedRateLimitService
{
    private array $config;
    private array $tierMultipliers;
    private CircuitBreakerService $circuitBreaker;
    private ThreatDetectionService $threatDetector;
    
    // Rate limiting tiers
    const TIER_FREE = 'free';
    const TIER_STARTER = 'starter';
    const TIER_PROFESSIONAL = 'professional';
    const TIER_ENTERPRISE = 'enterprise';
    
    // Rate limiting scopes
    const SCOPE_USER = 'user';
    const SCOPE_ORGANIZATION = 'organization';
    const SCOPE_ENDPOINT = 'endpoint';
    const SCOPE_GLOBAL = 'global';
    
    // Priority levels
    const PRIORITY_CRITICAL = 'critical';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_LOW = 'low';

    public function __construct(
        CircuitBreakerService $circuitBreaker,
        ThreatDetectionService $threatDetector
    ) {
        $this->config = config('rate_limiting.enhanced', []);
        $this->circuitBreaker = $circuitBreaker;
        $this->threatDetector = $threatDetector;
        $this->initializeTierMultipliers();
    }

    /**
     * Initialize tier-based multipliers for rate limits
     */
    private function initializeTierMultipliers(): void
    {
        $this->tierMultipliers = [
            self::TIER_FREE => [
                'requests_per_minute' => 1.0,
                'burst_multiplier' => 1.0,
                'priority_boost' => 0,
                'queue_size' => 10
            ],
            self::TIER_STARTER => [
                'requests_per_minute' => 5.0,
                'burst_multiplier' => 2.0,
                'priority_boost' => 1,
                'queue_size' => 50
            ],
            self::TIER_PROFESSIONAL => [
                'requests_per_minute' => 20.0,
                'burst_multiplier' => 5.0,
                'priority_boost' => 2,
                'queue_size' => 200
            ],
            self::TIER_ENTERPRISE => [
                'requests_per_minute' => 100.0,
                'burst_multiplier' => 10.0,
                'priority_boost' => 5,
                'queue_size' => 1000
            ]
        ];
    }

    /**
     * Apply comprehensive rate limiting with all tiers and protections
     */
    public function applyRateLimit(Request $request, array $context = []): array
    {
        $startTime = microtime(true);
        
        try {
            // Extract context information
            $clientId = $this->getClientIdentifier($request);
            $tenant = $this->getTenantFromRequest($request);
            $endpoint = $this->getEndpointCategory($request);
            
            // Check for DDoS patterns
            $threatLevel = $this->threatDetector->assessThreat($request, $clientId);
            if ($threatLevel >= ThreatDetectionService::THREAT_HIGH) {
                return $this->handleThreatResponse($request, $clientId, $threatLevel);
            }
            
            // Check circuit breaker status
            if ($this->circuitBreaker->isOpen($endpoint)) {
                return $this->handleCircuitBreakerOpen($request, $endpoint);
            }
            
            // Apply tiered rate limiting
            $limits = $this->calculateTieredLimits($tenant, $endpoint, $request);
            
            // Apply rate limiting at different scopes
            $results = [
                self::SCOPE_USER => $this->applyUserRateLimit($clientId, $limits, $request),
                self::SCOPE_ORGANIZATION => $this->applyOrganizationRateLimit($tenant, $limits, $request),
                self::SCOPE_ENDPOINT => $this->applyEndpointRateLimit($endpoint, $limits, $request),
                self::SCOPE_GLOBAL => $this->applyGlobalRateLimit($limits, $request)
            ];
            
            // Check if any scope is rate limited
            $blocked = collect($results)->contains('allowed', false);
            $blockedScope = collect($results)->filter(fn($r) => !$r['allowed'])->keys()->first();
            
            if ($blocked) {
                // Check if request can be queued
                if ($this->canQueueRequest($tenant, $request)) {
                    return $this->queueRequest($request, $clientId, $tenant, $results);
                }
                
                // Track circuit breaker failure
                $this->circuitBreaker->recordFailure($endpoint);
                
                return $this->handleRateLimit($request, $clientId, $blockedScope, $results[$blockedScope]);
            }
            
            // Track circuit breaker success
            $this->circuitBreaker->recordSuccess($endpoint);
            
            // All scopes passed, log successful request
            $this->logSuccessfulRequest($request, $clientId, $results, microtime(true) - $startTime);
            
            return [
                'allowed' => true,
                'scopes' => $results,
                'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'tier' => $tenant ? $tenant->tier : self::TIER_FREE,
                'threat_level' => $threatLevel
            ];
            
        } catch (\Exception $e) {
            Log::error('Enhanced rate limiting error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_id' => $request->header('X-Request-ID')
            ]);
            
            // Fail open but log the error
            return ['allowed' => true, 'error' => 'Rate limiting service unavailable'];
        }
    }

    /**
     * Calculate tiered limits based on tenant and endpoint
     */
    private function calculateTieredLimits(?Tenant $tenant, string $endpoint, Request $request): array
    {
        $tier = $tenant ? $tenant->tier : self::TIER_FREE;
        $tierConfig = $this->tierMultipliers[$tier] ?? $this->tierMultipliers[self::TIER_FREE];
        
        // Base limits from configuration
        $baseLimits = $this->config['base_limits'] ?? [
            'requests_per_minute' => 100,
            'burst_size' => 20,
            'window_size' => 60
        ];
        
        // Endpoint-specific multipliers
        $endpointMultipliers = $this->config['endpoint_multipliers'][$endpoint] ?? [
            'requests_multiplier' => 1.0,
            'burst_multiplier' => 1.0
        ];
        
        // Calculate final limits
        $limits = [
            'requests_per_minute' => (int) ($baseLimits['requests_per_minute'] 
                * $tierConfig['requests_per_minute'] 
                * $endpointMultipliers['requests_multiplier']),
            'burst_size' => (int) ($baseLimits['burst_size'] 
                * $tierConfig['burst_multiplier'] 
                * $endpointMultipliers['burst_multiplier']),
            'window_size' => $baseLimits['window_size'],
            'priority' => $this->getRequestPriority($request, $tenant),
            'queue_size' => $tierConfig['queue_size'],
            'tier' => $tier,
            'endpoint' => $endpoint
        ];
        
        // Apply dynamic adjustments
        return $this->applyDynamicAdjustments($limits, $request);
    }

    /**
     * Apply user-level rate limiting
     */
    private function applyUserRateLimit(string $clientId, array $limits, Request $request): array
    {
        $key = "rate_limit:user:{$clientId}";
        return $this->applyTokenBucketAlgorithm($key, $limits, 'user');
    }

    /**
     * Apply organization-level rate limiting
     */
    private function applyOrganizationRateLimit(?Tenant $tenant, array $limits, Request $request): array
    {
        if (!$tenant) {
            return ['allowed' => true, 'scope' => 'organization', 'reason' => 'no_tenant'];
        }
        
        $key = "rate_limit:org:{$tenant->id}";
        $orgLimits = $limits;
        
        // Apply tenant-specific API rate limits
        if ($tenant->max_api_calls_per_minute > 0) {
            $orgLimits['requests_per_minute'] = min(
                $orgLimits['requests_per_minute'],
                $tenant->max_api_calls_per_minute
            );
        }
        
        return $this->applyTokenBucketAlgorithm($key, $orgLimits, 'organization');
    }

    /**
     * Apply endpoint-level rate limiting
     */
    private function applyEndpointRateLimit(string $endpoint, array $limits, Request $request): array
    {
        $key = "rate_limit:endpoint:{$endpoint}";
        $endpointLimits = $limits;
        
        // Specific endpoint protections
        $endpointConfig = $this->config['endpoint_protection'][$endpoint] ?? [];
        if (!empty($endpointConfig)) {
            $endpointLimits = array_merge($endpointLimits, $endpointConfig);
        }
        
        return $this->applySlidingWindowAlgorithm($key, $endpointLimits, 'endpoint');
    }

    /**
     * Apply global rate limiting
     */
    private function applyGlobalRateLimit(array $limits, Request $request): array
    {
        $key = "rate_limit:global";
        $globalLimits = $limits;
        
        // Apply system-wide limits
        $systemLoad = $this->getSystemLoad();
        if ($systemLoad > 0.8) {
            $globalLimits['requests_per_minute'] = (int) ($globalLimits['requests_per_minute'] * 0.5);
        }
        
        return $this->applyFixedWindowAlgorithm($key, $globalLimits, 'global');
    }

    /**
     * Enhanced token bucket algorithm with priority support
     */
    private function applyTokenBucketAlgorithm(string $key, array $limits, string $scope): array
    {
        $now = time();
        $priority = $limits['priority'] ?? self::PRIORITY_MEDIUM;
        
        // Use Redis for atomic operations
        $lua = "
            local key = KEYS[1]
            local capacity = tonumber(ARGV[1])
            local refill_rate = tonumber(ARGV[2])
            local now = tonumber(ARGV[3])
            local priority_boost = tonumber(ARGV[4])
            
            local bucket = redis.call('HMGET', key, 'tokens', 'last_refill')
            local tokens = tonumber(bucket[1]) or capacity
            local last_refill = tonumber(bucket[2]) or now
            
            -- Calculate tokens to add based on time elapsed
            local time_elapsed = now - last_refill
            local tokens_to_add = (time_elapsed / 60) * refill_rate
            tokens = math.min(capacity, tokens + tokens_to_add)
            
            -- Apply priority boost (high priority requests cost fewer tokens)
            local token_cost = math.max(0.1, 1 - (priority_boost * 0.1))
            
            local allowed = tokens >= token_cost
            if allowed then
                tokens = tokens - token_cost
            end
            
            -- Update bucket
            redis.call('HMSET', key, 'tokens', tokens, 'last_refill', now)
            redis.call('EXPIRE', key, 3600)
            
            return {allowed and 1 or 0, math.floor(tokens), capacity, now + (60 - (now % 60))}
        ";
        
        $priorityBoost = $this->getPriorityBoost($priority);
        $result = Redis::eval($lua, 1, $key, 
            $limits['requests_per_minute'], 
            $limits['requests_per_minute'], 
            $now, 
            $priorityBoost
        );
        
        return [
            'allowed' => (bool) $result[0],
            'remaining' => (int) $result[1],
            'limit' => (int) $limits['requests_per_minute'],
            'reset_time' => (int) $result[3],
            'algorithm' => 'token_bucket_priority',
            'scope' => $scope,
            'priority' => $priority
        ];
    }

    /**
     * Enhanced sliding window algorithm with burst protection
     */
    private function applySlidingWindowAlgorithm(string $key, array $limits, string $scope): array
    {
        $now = time();
        $windowSize = $limits['window_size'];
        $windowStart = $now - $windowSize;
        
        $lua = "
            local key = KEYS[1]
            local window_start = tonumber(ARGV[1])
            local limit = tonumber(ARGV[2])
            local now = tonumber(ARGV[3])
            local burst_size = tonumber(ARGV[4])
            
            -- Remove old entries
            redis.call('ZREMRANGEBYSCORE', key, 0, window_start)
            
            -- Count current requests
            local current_count = redis.call('ZCARD', key)
            
            -- Check burst protection (requests in last 10 seconds)
            local burst_start = now - 10
            local burst_count = redis.call('ZCOUNT', key, burst_start, now)
            
            local allowed = current_count < limit and burst_count < burst_size
            
            if allowed then
                redis.call('ZADD', key, now, now .. ':' .. math.random(1000000))
                current_count = current_count + 1
            end
            
            redis.call('EXPIRE', key, window_start + 120)
            
            return {allowed and 1 or 0, math.max(0, limit - current_count), current_count, burst_count}
        ";
        
        $result = Redis::eval($lua, 1, $key, 
            $windowStart, 
            $limits['requests_per_minute'], 
            $now, 
            $limits['burst_size']
        );
        
        return [
            'allowed' => (bool) $result[0],
            'remaining' => (int) $result[1],
            'limit' => (int) $limits['requests_per_minute'],
            'reset_time' => $windowStart + $windowSize,
            'algorithm' => 'sliding_window_burst',
            'scope' => $scope,
            'current_count' => (int) $result[2],
            'burst_count' => (int) $result[3]
        ];
    }

    /**
     * Enhanced fixed window algorithm with predictive scaling
     */
    private function applyFixedWindowAlgorithm(string $key, array $limits, string $scope): array
    {
        $windowSize = $limits['window_size'];
        $now = time();
        $window = (int) ($now / $windowSize) * $windowSize;
        $windowKey = "{$key}:{$window}";
        
        $lua = "
            local key = KEYS[1]
            local limit = tonumber(ARGV[1])
            local window_size = tonumber(ARGV[2])
            
            local count = redis.call('INCR', key)
            if count == 1 then
                redis.call('EXPIRE', key, window_size + 10)
            end
            
            local allowed = count <= limit
            
            return {allowed and 1 or 0, math.max(0, limit - count), count}
        ";
        
        $result = Redis::eval($lua, 1, $windowKey, 
            $limits['requests_per_minute'], 
            $windowSize
        );
        
        return [
            'allowed' => (bool) $result[0],
            'remaining' => (int) $result[1],
            'limit' => (int) $limits['requests_per_minute'],
            'reset_time' => $window + $windowSize,
            'algorithm' => 'fixed_window_predictive',
            'scope' => $scope,
            'current_count' => (int) $result[2]
        ];
    }

    /**
     * Check if request can be queued for delayed processing
     */
    private function canQueueRequest(?Tenant $tenant, Request $request): bool
    {
        if (!$tenant) {
            return false;
        }
        
        $tier = $tenant->tier;
        $tierConfig = $this->tierMultipliers[$tier] ?? $this->tierMultipliers[self::TIER_FREE];
        
        // Check current queue size
        $queueKey = "rate_limit:queue:{$tenant->id}";
        $currentQueueSize = Redis::llen($queueKey);
        
        return $currentQueueSize < $tierConfig['queue_size'];
    }

    /**
     * Queue request for delayed processing
     */
    private function queueRequest(Request $request, string $clientId, ?Tenant $tenant, array $results): array
    {
        $queueKey = "rate_limit:queue:{$tenant->id}";
        $priority = $this->getRequestPriority($request, $tenant);
        
        $queueItem = [
            'client_id' => $clientId,
            'request_data' => [
                'method' => $request->method(),
                'path' => $request->path(),
                'headers' => $request->headers->all(),
                'timestamp' => time()
            ],
            'priority' => $priority,
            'retry_count' => 0,
            'queued_at' => time()
        ];
        
        // Use priority queue (higher priority = lower numeric value)
        $priorityScore = $this->getPriorityScore($priority);
        Redis::zadd("rate_limit:priority_queue:{$tenant->id}", $priorityScore, json_encode($queueItem));
        Redis::expire("rate_limit:priority_queue:{$tenant->id}", 300); // 5 minutes
        
        return [
            'allowed' => false,
            'queued' => true,
            'queue_position' => Redis::zrank("rate_limit:priority_queue:{$tenant->id}", json_encode($queueItem)),
            'estimated_wait_seconds' => $this->calculateEstimatedWait($tenant, $priority),
            'scope' => 'queued',
            'priority' => $priority
        ];
    }

    /**
     * Get request priority based on endpoint and user tier
     */
    private function getRequestPriority(Request $request, ?Tenant $tenant): string
    {
        // Critical endpoints always get high priority
        $criticalEndpoints = ['/health', '/status', '/api/v1/security/*'];
        foreach ($criticalEndpoints as $pattern) {
            if ($request->is($pattern)) {
                return self::PRIORITY_CRITICAL;
            }
        }
        
        // Admin users get higher priority
        if ($tenant && in_array($tenant->tier, [self::TIER_ENTERPRISE, self::TIER_PROFESSIONAL])) {
            return self::PRIORITY_HIGH;
        }
        
        // API endpoints get medium priority
        if ($request->is('api/*')) {
            return self::PRIORITY_MEDIUM;
        }
        
        return self::PRIORITY_LOW;
    }

    /**
     * Get priority boost value for token bucket
     */
    private function getPriorityBoost(string $priority): float
    {
        return match($priority) {
            self::PRIORITY_CRITICAL => 0.9,
            self::PRIORITY_HIGH => 0.7,
            self::PRIORITY_MEDIUM => 0.5,
            self::PRIORITY_LOW => 0.0,
            default => 0.0
        };
    }

    /**
     * Get priority score for queue ordering (lower = higher priority)
     */
    private function getPriorityScore(string $priority): int
    {
        return match($priority) {
            self::PRIORITY_CRITICAL => 1,
            self::PRIORITY_HIGH => 2,
            self::PRIORITY_MEDIUM => 3,
            self::PRIORITY_LOW => 4,
            default => 5
        };
    }

    /**
     * Calculate estimated wait time for queued requests
     */
    private function calculateEstimatedWait(?Tenant $tenant, string $priority): int
    {
        if (!$tenant) {
            return 60; // Default 1 minute
        }
        
        $queueSize = Redis::zcard("rate_limit:priority_queue:{$tenant->id}");
        $processingRate = $tenant->max_api_calls_per_minute ?: 100;
        
        // Estimate based on queue size and processing rate
        $baseWait = (int) (($queueSize / $processingRate) * 60);
        
        // Adjust for priority
        $priorityMultiplier = match($priority) {
            self::PRIORITY_CRITICAL => 0.1,
            self::PRIORITY_HIGH => 0.3,
            self::PRIORITY_MEDIUM => 0.7,
            self::PRIORITY_LOW => 1.0,
            default => 1.0
        };
        
        return max(1, (int) ($baseWait * $priorityMultiplier));
    }

    /**
     * Apply dynamic adjustments based on current conditions
     */
    private function applyDynamicAdjustments(array $limits, Request $request): array
    {
        // System load adjustment
        $systemLoad = $this->getSystemLoad();
        if ($systemLoad > 0.8) {
            $limits['requests_per_minute'] = (int) ($limits['requests_per_minute'] * 0.6);
            $limits['burst_size'] = (int) ($limits['burst_size'] * 0.6);
        }
        
        // Time-based adjustments (peak hours)
        $hour = (int) date('H');
        if ($hour >= 9 && $hour <= 17) { // Business hours
            $limits['requests_per_minute'] = (int) ($limits['requests_per_minute'] * 1.2);
        }
        
        return $limits;
    }

    /**
     * Handle threat-level responses
     */
    private function handleThreatResponse(Request $request, string $clientId, int $threatLevel): array
    {
        SecurityAuditLog::logEvent(
            SecurityAuditLog::EVENT_SECURITY_VIOLATION,
            "High threat level detected: {$threatLevel}",
            SecurityAuditLog::SEVERITY_CRITICAL,
            [
                'client_id' => $clientId,
                'threat_level' => $threatLevel,
                'path' => $request->path(),
                'ip' => $request->ip()
            ]
        );
        
        // Immediate block for high threats
        $blockDuration = match($threatLevel) {
            ThreatDetectionService::THREAT_CRITICAL => 3600, // 1 hour
            ThreatDetectionService::THREAT_HIGH => 900,      // 15 minutes
            default => 300                                    // 5 minutes
        };
        
        Cache::put("threat_block:{$clientId}", true, $blockDuration);
        
        return [
            'allowed' => false,
            'blocked' => true,
            'reason' => 'threat_detected',
            'threat_level' => $threatLevel,
            'block_duration' => $blockDuration
        ];
    }

    /**
     * Handle circuit breaker open state
     */
    private function handleCircuitBreakerOpen(Request $request, string $endpoint): array
    {
        return [
            'allowed' => false,
            'blocked' => true,
            'reason' => 'circuit_breaker_open',
            'endpoint' => $endpoint,
            'retry_after' => $this->circuitBreaker->getRecoveryTime($endpoint)
        ];
    }

    /**
     * Get client identifier with enhanced fingerprinting
     */
    private function getClientIdentifier(Request $request): string
    {
        // Priority: API key > JWT > User ID > IP + User Agent hash
        if ($apiKey = $request->header('X-API-Key')) {
            return 'api_key:' . hash('sha256', $apiKey);
        }
        
        if ($token = $request->bearerToken()) {
            return 'jwt:' . hash('sha256', $token);
        }
        
        if ($userId = auth()->id()) {
            return 'user:' . $userId;
        }
        
        // Create composite identifier for anonymous users
        $ip = $request->ip();
        $userAgent = $request->userAgent() ?: 'unknown';
        $fingerprint = hash('sha256', $ip . '|' . $userAgent);
        
        return 'anonymous:' . $fingerprint;
    }

    /**
     * Get tenant from request context
     */
    private function getTenantFromRequest(Request $request): ?Tenant
    {
        // Try multiple methods to identify tenant
        if ($tenantId = $request->header('X-Tenant-ID')) {
            return Tenant::find($tenantId);
        }
        
        if ($domain = $request->header('Host')) {
            return Tenant::where('domain', $domain)->first();
        }
        
        if ($user = auth()->user()) {
            return $user->tenant ?? null;
        }
        
        return null;
    }

    /**
     * Get endpoint category for specific rate limiting
     */
    private function getEndpointCategory(Request $request): string
    {
        $path = $request->path();
        
        // Categorize endpoints
        if (str_starts_with($path, 'api/v1/orchestration')) {
            return 'orchestration';
        }
        
        if (str_starts_with($path, 'api/v1/agents')) {
            return 'agents';
        }
        
        if (str_starts_with($path, 'api/v1/security')) {
            return 'security';
        }
        
        if (str_starts_with($path, 'webhooks')) {
            return 'webhooks';
        }
        
        if (str_starts_with($path, 'api/')) {
            return 'api';
        }
        
        return 'web';
    }

    /**
     * Get current system load
     */
    private function getSystemLoad(): float
    {
        if (!function_exists('sys_getloadavg')) {
            return 0.5;
        }
        
        $load = sys_getloadavg();
        $cpuCount = $this->getCpuCount();
        
        return min(1.0, $load[0] / $cpuCount);
    }

    /**
     * Get CPU count
     */
    private function getCpuCount(): int
    {
        if (is_file('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            return substr_count($cpuinfo, 'processor') ?: 1;
        }
        
        return 1;
    }

    /**
     * Handle rate limit exceeded
     */
    private function handleRateLimit(Request $request, string $clientId, string $scope, array $result): array
    {
        SecurityAuditLog::logEvent(
            SecurityAuditLog::EVENT_RATE_LIMIT,
            "Rate limit exceeded in scope: {$scope}",
            SecurityAuditLog::SEVERITY_WARNING,
            [
                'client_id' => $clientId,
                'scope' => $scope,
                'path' => $request->path(),
                'result' => $result
            ]
        );
        
        return [
            'allowed' => false,
            'scope' => $scope,
            'limit' => $result['limit'],
            'remaining' => $result['remaining'],
            'reset_time' => $result['reset_time'],
            'retry_after' => max(1, $result['reset_time'] - time())
        ];
    }

    /**
     * Log successful rate limit check
     */
    private function logSuccessfulRequest(Request $request, string $clientId, array $results, float $processingTime): void
    {
        if ($this->config['log_successful_requests'] ?? false) {
            SecurityAuditLog::logEvent(
                SecurityAuditLog::EVENT_DATA_ACCESS,
                'Multi-tier rate limit check passed',
                SecurityAuditLog::SEVERITY_INFO,
                [
                    'client_id' => $clientId,
                    'path' => $request->path(),
                    'scopes' => $results,
                    'processing_time_ms' => round($processingTime * 1000, 2)
                ]
            );
        }
    }
}