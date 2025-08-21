<?php

namespace App\Domains\Orchestration\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * Advanced Caching Service for GENESIS Orchestration
 * 
 * Multi-tier intelligent caching system optimized for LAG/RCR algorithms:
 * - L1: In-memory application cache (fastest access)
 * - L2: Redis distributed cache (shared across instances)  
 * - L3: Database result cache (persistent storage)
 * - Smart invalidation with dependency tracking
 * - Cache warming and prefetching strategies
 * - Performance analytics and optimization
 * 
 * Performance Targets:
 * - Cache hit ratio: ≥85%
 * - L1 access time: <1ms
 * - L2 access time: <5ms
 * - Cache efficiency: ≥90%
 * - Memory utilization: ≤512MB
 */
class AdvancedCacheService
{
    /**
     * Multi-tier cache configuration
     */
    private array $config = [
        'l1_cache' => [
            'enabled' => true,
            'max_size' => 1000,           // Max items in memory
            'ttl' => 300,                 // 5 minutes default TTL
            'memory_limit' => 128,        // 128MB memory limit
        ],
        'l2_cache' => [
            'enabled' => true,
            'prefix' => 'genesis:orch:',
            'ttl' => 3600,               // 1 hour default TTL
            'cluster' => true,            // Redis cluster support
        ],
        'l3_cache' => [
            'enabled' => true,
            'table' => 'orchestration_cache',
            'ttl' => 86400,              // 24 hours default TTL
            'cleanup_interval' => 3600,   // Cleanup every hour
        ],
        'strategies' => [
            'lag_decomposition' => [
                'tier_preference' => ['L1', 'L2', 'L3'],
                'ttl_multiplier' => 2.0,  // Longer TTL for expensive operations
                'prefetch_enabled' => true,
                'dependency_tracking' => true
            ],
            'rcr_routing' => [
                'tier_preference' => ['L1', 'L2'],
                'ttl_multiplier' => 1.5,
                'prefetch_enabled' => false, // Routing is context-dependent
                'dependency_tracking' => false
            ],
            'query_results' => [
                'tier_preference' => ['L1', 'L2', 'L3'],
                'ttl_multiplier' => 1.0,
                'prefetch_enabled' => true,
                'dependency_tracking' => true
            ]
        ]
    ];

    /**
     * L1 in-memory cache
     */
    private array $l1Cache = [];
    private array $l1Metadata = [];
    private int $l1Size = 0;

    /**
     * Cache performance metrics
     */
    private array $metrics = [
        'requests' => 0,
        'hits' => ['l1' => 0, 'l2' => 0, 'l3' => 0],
        'misses' => 0,
        'writes' => ['l1' => 0, 'l2' => 0, 'l3' => 0],
        'evictions' => ['l1' => 0, 'l2' => 0, 'l3' => 0],
        'response_times' => [],
        'memory_usage' => 0
    ];

    /**
     * Cache dependency graph
     */
    private array $dependencyGraph = [];

    public function __construct()
    {
        $this->initializeCache();
        $this->loadConfiguration();
    }

    /**
     * Get cached value with intelligent tier selection
     */
    public function get(string $key, string $strategy = 'query_results'): mixed
    {
        $startTime = microtime(true);
        $this->metrics['requests']++;

        $strategyConfig = $this->config['strategies'][$strategy] ?? $this->config['strategies']['query_results'];
        $tierPreference = $strategyConfig['tier_preference'];

        // Try each tier in preference order
        foreach ($tierPreference as $tier) {
            $value = $this->getTierValue($key, $tier);
            if ($value !== null) {
                // Cache hit - update metrics and propagate upward if needed
                $this->metrics['hits'][strtolower($tier)]++;
                $this->recordResponseTime($startTime);
                
                // Propagate to higher tiers for faster future access
                $this->propagateUpward($key, $value, $tier, $tierPreference);
                
                Log::debug("Cache hit", [
                    'key' => $key,
                    'tier' => $tier,
                    'strategy' => $strategy,
                    'response_time' => (microtime(true) - $startTime) * 1000
                ]);

                return $value;
            }
        }

        // Cache miss
        $this->metrics['misses']++;
        $this->recordResponseTime($startTime);

        Log::debug("Cache miss", [
            'key' => $key,
            'strategy' => $strategy,
            'response_time' => (microtime(true) - $startTime) * 1000
        ]);

        return null;
    }

    /**
     * Store value in cache with intelligent tier distribution
     */
    public function put(string $key, mixed $value, string $strategy = 'query_results', ?int $ttl = null): bool
    {
        $strategyConfig = $this->config['strategies'][$strategy] ?? $this->config['strategies']['query_results'];
        $tierPreference = $strategyConfig['tier_preference'];
        $ttlMultiplier = $strategyConfig['ttl_multiplier'];

        // Calculate effective TTL
        $effectiveTtl = $ttl ?? ($this->config['l2_cache']['ttl'] * $ttlMultiplier);

        $success = true;
        
        // Store in all preferred tiers
        foreach ($tierPreference as $tier) {
            $tierSuccess = $this->putTierValue($key, $value, $tier, $effectiveTtl);
            if ($tierSuccess) {
                $this->metrics['writes'][strtolower($tier)]++;
            }
            $success = $success && $tierSuccess;
        }

        // Handle dependency tracking
        if ($strategyConfig['dependency_tracking']) {
            $this->updateDependencyGraph($key, $value);
        }

        Log::debug("Cache write", [
            'key' => $key,
            'strategy' => $strategy,
            'tiers' => $tierPreference,
            'ttl' => $effectiveTtl,
            'success' => $success
        ]);

        return $success;
    }

    /**
     * Get value from specific cache tier
     */
    private function getTierValue(string $key, string $tier): mixed
    {
        switch ($tier) {
            case 'L1':
                return $this->getL1Value($key);
            case 'L2':
                return $this->getL2Value($key);
            case 'L3':
                return $this->getL3Value($key);
            default:
                return null;
        }
    }

    /**
     * Put value in specific cache tier
     */
    private function putTierValue(string $key, mixed $value, string $tier, int $ttl): bool
    {
        switch ($tier) {
            case 'L1':
                return $this->putL1Value($key, $value, $ttl);
            case 'L2':
                return $this->putL2Value($key, $value, $ttl);
            case 'L3':
                return $this->putL3Value($key, $value, $ttl);
            default:
                return false;
        }
    }

    /**
     * L1 Cache Operations (In-Memory)
     */
    private function getL1Value(string $key): mixed
    {
        if (!$this->config['l1_cache']['enabled']) {
            return null;
        }

        if (!isset($this->l1Cache[$key])) {
            return null;
        }

        $metadata = $this->l1Metadata[$key];
        
        // Check TTL expiration
        if ($metadata['expires_at'] < time()) {
            $this->evictL1Key($key);
            return null;
        }

        // Update access time for LRU
        $this->l1Metadata[$key]['accessed_at'] = time();
        $this->l1Metadata[$key]['access_count']++;

        return $this->l1Cache[$key];
    }

    private function putL1Value(string $key, mixed $value, int $ttl): bool
    {
        if (!$this->config['l1_cache']['enabled']) {
            return false;
        }

        // Check memory limits
        $valueSize = $this->calculateSize($value);
        $this->enforceL1Limits($valueSize);

        $this->l1Cache[$key] = $value;
        $this->l1Metadata[$key] = [
            'created_at' => time(),
            'accessed_at' => time(),
            'expires_at' => time() + $ttl,
            'access_count' => 1,
            'size' => $valueSize
        ];

        $this->l1Size += $valueSize;
        $this->metrics['memory_usage'] = $this->l1Size;

        return true;
    }

    /**
     * L2 Cache Operations (Redis)
     */
    private function getL2Value(string $key): mixed
    {
        if (!$this->config['l2_cache']['enabled']) {
            return null;
        }

        try {
            $cacheKey = $this->config['l2_cache']['prefix'] . $key;
            $value = Redis::get($cacheKey);
            
            if ($value === null) {
                return null;
            }

            return unserialize($value);
        } catch (\Exception $e) {
            Log::warning('L2 cache read error', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function putL2Value(string $key, mixed $value, int $ttl): bool
    {
        if (!$this->config['l2_cache']['enabled']) {
            return false;
        }

        try {
            $cacheKey = $this->config['l2_cache']['prefix'] . $key;
            $serializedValue = serialize($value);
            
            return Redis::setex($cacheKey, $ttl, $serializedValue);
        } catch (\Exception $e) {
            Log::warning('L2 cache write error', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * L3 Cache Operations (Database)
     */
    private function getL3Value(string $key): mixed
    {
        if (!$this->config['l3_cache']['enabled']) {
            return null;
        }

        try {
            $result = \DB::table($this->config['l3_cache']['table'])
                ->where('cache_key', $key)
                ->where('expires_at', '>', now())
                ->first();

            if (!$result) {
                return null;
            }

            // Update access statistics
            \DB::table($this->config['l3_cache']['table'])
                ->where('cache_key', $key)
                ->update([
                    'accessed_at' => now(),
                    'access_count' => \DB::raw('access_count + 1')
                ]);

            return unserialize($result->cache_value);
        } catch (\Exception $e) {
            Log::warning('L3 cache read error', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function putL3Value(string $key, mixed $value, int $ttl): bool
    {
        if (!$this->config['l3_cache']['enabled']) {
            return false;
        }

        try {
            $serializedValue = serialize($value);
            $expiresAt = now()->addSeconds($ttl);

            \DB::table($this->config['l3_cache']['table'])
                ->updateOrInsert(
                    ['cache_key' => $key],
                    [
                        'cache_value' => $serializedValue,
                        'created_at' => now(),
                        'accessed_at' => now(),
                        'expires_at' => $expiresAt,
                        'access_count' => 1,
                        'strategy' => 'default'
                    ]
                );

            return true;
        } catch (\Exception $e) {
            Log::warning('L3 cache write error', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Propagate value to higher cache tiers
     */
    private function propagateUpward(string $key, mixed $value, string $currentTier, array $tierPreference): void
    {
        $currentIndex = array_search($currentTier, $tierPreference);
        
        // Propagate to all higher tiers
        for ($i = 0; $i < $currentIndex; $i++) {
            $higherTier = $tierPreference[$i];
            $tierTtl = $this->getTierTtl($higherTier);
            $this->putTierValue($key, $value, $higherTier, $tierTtl);
        }
    }

    /**
     * Enforce L1 cache limits with intelligent eviction
     */
    private function enforceL1Limits(int $newValueSize): void
    {
        $maxSize = $this->config['l1_cache']['max_size'];
        $maxMemory = $this->config['l1_cache']['memory_limit'] * 1024 * 1024; // Convert to bytes

        // Check item count limit
        while (count($this->l1Cache) >= $maxSize) {
            $this->evictL1LRU();
        }

        // Check memory limit
        while (($this->l1Size + $newValueSize) > $maxMemory) {
            $this->evictL1LRU();
        }
    }

    /**
     * Evict least recently used item from L1
     */
    private function evictL1LRU(): void
    {
        if (empty($this->l1Metadata)) {
            return;
        }

        // Find LRU item
        $lruKey = null;
        $lruTime = PHP_INT_MAX;

        foreach ($this->l1Metadata as $key => $metadata) {
            if ($metadata['accessed_at'] < $lruTime) {
                $lruTime = $metadata['accessed_at'];
                $lruKey = $key;
            }
        }

        if ($lruKey) {
            $this->evictL1Key($lruKey);
        }
    }

    /**
     * Evict specific key from L1
     */
    private function evictL1Key(string $key): void
    {
        if (isset($this->l1Cache[$key])) {
            $this->l1Size -= $this->l1Metadata[$key]['size'];
            unset($this->l1Cache[$key]);
            unset($this->l1Metadata[$key]);
            $this->metrics['evictions']['l1']++;
        }
    }

    /**
     * Cache warming for frequently accessed data
     */
    public function warmCache(array $keys, string $strategy = 'query_results'): array
    {
        $results = ['warmed' => 0, 'failed' => 0, 'keys' => []];

        foreach ($keys as $key => $valueGenerator) {
            try {
                // Check if already cached
                if ($this->get($key, $strategy) !== null) {
                    continue;
                }

                // Generate value
                $value = is_callable($valueGenerator) ? $valueGenerator() : $valueGenerator;
                
                // Cache the value
                if ($this->put($key, $value, $strategy)) {
                    $results['warmed']++;
                    $results['keys'][] = $key;
                } else {
                    $results['failed']++;
                }
                
            } catch (\Exception $e) {
                $results['failed']++;
                Log::warning('Cache warming failed', [
                    'key' => $key,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::info('Cache warming completed', $results);
        return $results;
    }

    /**
     * Intelligent cache prefetching
     */
    public function prefetch(string $pattern, int $limit = 10): array
    {
        $prefetched = [];
        
        try {
            // Get access patterns from L3 cache
            $popularKeys = \DB::table($this->config['l3_cache']['table'])
                ->where('cache_key', 'like', $pattern)
                ->where('expires_at', '>', now())
                ->orderByDesc('access_count')
                ->limit($limit)
                ->pluck('cache_key');

            foreach ($popularKeys as $key) {
                $value = $this->getL3Value($key);
                if ($value !== null) {
                    // Promote to L1 and L2
                    $this->putL1Value($key, $value, 300);
                    $this->putL2Value($key, $value, 1800);
                    $prefetched[] = $key;
                }
            }

        } catch (\Exception $e) {
            Log::warning('Cache prefetching failed', [
                'pattern' => $pattern,
                'error' => $e->getMessage()
            ]);
        }

        return $prefetched;
    }

    /**
     * Invalidate cache with dependency tracking
     */
    public function invalidate(string $key, bool $cascadeInvalidation = true): bool
    {
        $success = true;

        // Invalidate from all tiers
        $this->evictL1Key($key);
        
        try {
            $l2Key = $this->config['l2_cache']['prefix'] . $key;
            Redis::del($l2Key);
        } catch (\Exception $e) {
            $success = false;
        }

        try {
            \DB::table($this->config['l3_cache']['table'])
                ->where('cache_key', $key)
                ->delete();
        } catch (\Exception $e) {
            $success = false;
        }

        // Cascade invalidation for dependent keys
        if ($cascadeInvalidation && isset($this->dependencyGraph[$key])) {
            foreach ($this->dependencyGraph[$key] as $dependentKey) {
                $this->invalidate($dependentKey, false); // Prevent infinite recursion
            }
        }

        Log::debug('Cache invalidation', [
            'key' => $key,
            'cascade' => $cascadeInvalidation,
            'success' => $success
        ]);

        return $success;
    }

    /**
     * Get comprehensive cache metrics
     */
    public function getMetrics(): array
    {
        $totalHits = array_sum($this->metrics['hits']);
        $hitRatio = $this->metrics['requests'] > 0 ? $totalHits / $this->metrics['requests'] : 0;
        $avgResponseTime = !empty($this->metrics['response_times']) ? 
            array_sum($this->metrics['response_times']) / count($this->metrics['response_times']) : 0;

        return array_merge($this->metrics, [
            'hit_ratio' => $hitRatio,
            'average_response_time' => $avgResponseTime,
            'l1_size' => count($this->l1Cache),
            'l1_memory_usage' => $this->l1Size,
            'l2_connection_status' => $this->checkL2Connection(),
            'l3_table_status' => $this->checkL3Table(),
            'performance_score' => $this->calculatePerformanceScore()
        ]);
    }

    /**
     * Calculate cache performance score
     */
    private function calculatePerformanceScore(): float
    {
        $metrics = $this->getMetrics();
        
        $hitRatioScore = min($metrics['hit_ratio'] / 0.85, 1.0); // Target: 85% hit ratio
        $responseTimeScore = max(0, 1.0 - ($metrics['average_response_time'] / 5.0)); // Target: <5ms
        $memoryScore = max(0, 1.0 - ($metrics['l1_memory_usage'] / (128 * 1024 * 1024))); // Target: <128MB

        return ($hitRatioScore * 0.5) + ($responseTimeScore * 0.3) + ($memoryScore * 0.2);
    }

    /**
     * Helper methods
     */
    private function getTierTtl(string $tier): int
    {
        return match ($tier) {
            'L1' => $this->config['l1_cache']['ttl'],
            'L2' => $this->config['l2_cache']['ttl'],
            'L3' => $this->config['l3_cache']['ttl'],
            default => 300
        };
    }

    private function calculateSize(mixed $value): int
    {
        return strlen(serialize($value));
    }

    private function recordResponseTime(float $startTime): void
    {
        $responseTime = (microtime(true) - $startTime) * 1000; // Convert to ms
        $this->metrics['response_times'][] = $responseTime;
        
        // Keep only last 1000 measurements
        if (count($this->metrics['response_times']) > 1000) {
            array_shift($this->metrics['response_times']);
        }
    }

    private function updateDependencyGraph(string $key, mixed $value): void
    {
        // Simple dependency tracking based on common patterns
        if (is_array($value) && isset($value['dependencies'])) {
            $this->dependencyGraph[$key] = $value['dependencies'];
        }
    }

    private function checkL2Connection(): bool
    {
        try {
            Redis::ping();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkL3Table(): bool
    {
        try {
            return \Schema::hasTable($this->config['l3_cache']['table']);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function initializeCache(): void
    {
        $this->l1Cache = [];
        $this->l1Metadata = [];
        $this->l1Size = 0;
        $this->dependencyGraph = [];
    }

    private function loadConfiguration(): void
    {
        $config = config('cache.orchestration');
        if ($config) {
            $this->config = array_merge_recursive($this->config, $config);
        }
    }

    /**
     * Cleanup expired entries
     */
    public function cleanup(): int
    {
        $cleaned = 0;

        // L1 cleanup
        $now = time();
        foreach ($this->l1Metadata as $key => $metadata) {
            if ($metadata['expires_at'] < $now) {
                $this->evictL1Key($key);
                $cleaned++;
            }
        }

        // L3 cleanup
        try {
            $l3Cleaned = \DB::table($this->config['l3_cache']['table'])
                ->where('expires_at', '<', now())
                ->delete();
            $cleaned += $l3Cleaned;
        } catch (\Exception $e) {
            Log::warning('L3 cache cleanup failed', ['error' => $e->getMessage()]);
        }

        Log::info('Cache cleanup completed', ['entries_cleaned' => $cleaned]);
        return $cleaned;
    }
}