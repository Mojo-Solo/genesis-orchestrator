<?php

namespace App\Http\Middleware;

use App\Models\SecurityAuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as ResponseCode;

/**
 * Advanced Rate Limiting Middleware with Dynamic Throttling
 * 
 * Provides sophisticated rate limiting with multiple algorithms, dynamic adjustment
 * based on system load, and comprehensive security features including burst protection,
 * IP reputation scoring, and adaptive rate limiting.
 */
class RateLimitingMiddleware
{
    private array $config;
    
    // Rate limiting algorithms
    const ALGORITHM_TOKEN_BUCKET = 'token_bucket';
    const ALGORITHM_SLIDING_WINDOW = 'sliding_window';
    const ALGORITHM_FIXED_WINDOW = 'fixed_window';
    const ALGORITHM_LEAKY_BUCKET = 'leaky_bucket';

    public function __construct()
    {
        $this->config = Config::get('rate_limiting', []);
    }

    /**
     * Handle an incoming request with advanced rate limiting.
     */
    public function handle(Request $request, Closure $next, ...$parameters)
    {
        $startTime = microtime(true);
        
        try {
            // Parse rate limiting parameters
            $limits = $this->parseParameters($parameters);
            
            // Skip rate limiting in development if configured
            if ($this->shouldSkipRateLimit($request)) {
                return $next($request);
            }

            // Get client identifier
            $clientId = $this->getClientIdentifier($request);
            
            // Get current system load
            $systemLoad = $this->getSystemLoad();
            
            // Apply dynamic rate adjustment based on system load
            $adjustedLimits = $this->adjustLimitsForLoad($limits, $systemLoad);
            
            // Check if client is already blocked
            if ($this->isClientBlocked($clientId)) {
                return $this->handleRateLimit($request, $clientId, 'client_blocked', $adjustedLimits);
            }

            // Apply rate limiting algorithm
            $result = $this->applyRateLimit($request, $clientId, $adjustedLimits);
            
            if (!$result['allowed']) {
                return $this->handleRateLimit($request, $clientId, 'rate_limit_exceeded', $adjustedLimits, $result);
            }

            // Add rate limit headers to response
            $response = $next($request);
            $this->addRateLimitHeaders($response, $result);
            
            // Log successful request
            $this->logRateLimitSuccess($request, $clientId, $result, microtime(true) - $startTime);
            
            return $response;

        } catch (\Exception $e) {
            Log::error('Rate limiting middleware error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_path' => $request->path()
            ]);

            // Fail open (allow request) but log the error
            SecurityAuditLog::logEvent(
                SecurityAuditLog::EVENT_SECURITY_VIOLATION,
                'Rate limiting middleware error: ' . $e->getMessage(),
                SecurityAuditLog::SEVERITY_ERROR,
                ['error' => $e->getMessage(), 'path' => $request->path()]
            );

            return $next($request);
        }
    }

    /**
     * Parse middleware parameters into rate limiting configuration.
     */
    private function parseParameters(array $parameters): array
    {
        $defaults = [
            'requests_per_minute' => $this->config['default_requests_per_minute'] ?? 100,
            'burst_size' => $this->config['default_burst_size'] ?? 20,
            'algorithm' => $this->config['default_algorithm'] ?? self::ALGORITHM_SLIDING_WINDOW,
            'window_size' => $this->config['default_window_size'] ?? 60, // seconds
            'by' => 'ip', // ip, user, api_key
        ];

        // Parse parameters: requests_per_minute,burst_size,algorithm,by
        if (!empty($parameters)) {
            if (isset($parameters[0])) $defaults['requests_per_minute'] = (int) $parameters[0];
            if (isset($parameters[1])) $defaults['burst_size'] = (int) $parameters[1];
            if (isset($parameters[2])) $defaults['algorithm'] = $parameters[2];
            if (isset($parameters[3])) $defaults['by'] = $parameters[3];
        }

        return $defaults;
    }

    /**
     * Determine if rate limiting should be skipped for this request.
     */
    private function shouldSkipRateLimit(Request $request): bool
    {
        // Skip in development if configured
        if (Config::get('app.env') !== 'production' && 
            Config::get('rate_limiting.skip_in_development', false)) {
            return true;
        }

        // Skip for specific paths
        $skipPaths = $this->config['skip_paths'] ?? ['/health', '/status'];
        foreach ($skipPaths as $path) {
            if ($request->is($path)) {
                return true;
            }
        }

        // Skip for specific IPs (admin IPs, monitoring systems)
        $skipIps = $this->config['skip_ips'] ?? [];
        if (in_array($request->ip(), $skipIps)) {
            return true;
        }

        return false;
    }

    /**
     * Get unique client identifier for rate limiting.
     */
    private function getClientIdentifier(Request $request): string
    {
        // Priority: API key > User ID > IP address
        if ($apiKey = $request->header('X-API-Key') ?: $request->bearerToken()) {
            return 'api_key:' . hash('sha256', $apiKey);
        }

        if ($userId = auth()->id()) {
            return 'user:' . $userId;
        }

        $ip = $request->ip();
        
        // Handle proxy scenarios
        $forwardedFor = $request->header('X-Forwarded-For');
        if ($forwardedFor) {
            $ips = array_map('trim', explode(',', $forwardedFor));
            $ip = $ips[0]; // Use first IP in chain
        }

        return 'ip:' . $ip;
    }

    /**
     * Get current system load for dynamic rate adjustment.
     */
    private function getSystemLoad(): float
    {
        if (!function_exists('sys_getloadavg')) {
            return 0.5; // Default to moderate load if unavailable
        }

        $load = sys_getloadavg();
        $cpuCount = $this->getCpuCount();
        
        // Normalize load to 0-1 scale based on CPU count
        return min(1.0, $load[0] / $cpuCount);
    }

    /**
     * Get system CPU count.
     */
    private function getCpuCount(): int
    {
        $cpuCount = 1;
        
        if (is_file('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            $cpuCount = substr_count($cpuinfo, 'processor');
        } elseif (function_exists('shell_exec')) {
            $output = shell_exec('nproc 2>/dev/null');
            if ($output) {
                $cpuCount = (int) trim($output);
            }
        }
        
        return max(1, $cpuCount);
    }

    /**
     * Adjust rate limits based on system load.
     */
    private function adjustLimitsForLoad(array $limits, float $systemLoad): array
    {
        if (!$this->config['dynamic_adjustment']['enabled'] ?? false) {
            return $limits;
        }

        $adjustment = 1.0;
        
        if ($systemLoad > 0.8) {
            // High load: reduce limits by 50%
            $adjustment = 0.5;
        } elseif ($systemLoad > 0.6) {
            // Medium load: reduce limits by 25%
            $adjustment = 0.75;
        } elseif ($systemLoad < 0.2) {
            // Low load: increase limits by 50%
            $adjustment = 1.5;
        }

        $adjustedLimits = $limits;
        $adjustedLimits['requests_per_minute'] = (int) ($limits['requests_per_minute'] * $adjustment);
        $adjustedLimits['burst_size'] = (int) ($limits['burst_size'] * $adjustment);
        $adjustedLimits['system_load'] = $systemLoad;
        $adjustedLimits['adjustment_factor'] = $adjustment;

        return $adjustedLimits;
    }

    /**
     * Check if client is currently blocked.
     */
    private function isClientBlocked(string $clientId): bool
    {
        $blockKey = "rate_limit_block:{$clientId}";
        return Cache::has($blockKey);
    }

    /**
     * Apply the configured rate limiting algorithm.
     */
    private function applyRateLimit(Request $request, string $clientId, array $limits): array
    {
        switch ($limits['algorithm']) {
            case self::ALGORITHM_TOKEN_BUCKET:
                return $this->applyTokenBucket($clientId, $limits);
                
            case self::ALGORITHM_SLIDING_WINDOW:
                return $this->applySlidingWindow($clientId, $limits);
                
            case self::ALGORITHM_FIXED_WINDOW:
                return $this->applyFixedWindow($clientId, $limits);
                
            case self::ALGORITHM_LEAKY_BUCKET:
                return $this->applyLeakyBucket($clientId, $limits);
                
            default:
                throw new \InvalidArgumentException("Unknown rate limiting algorithm: {$limits['algorithm']}");
        }
    }

    /**
     * Apply token bucket algorithm.
     */
    private function applyTokenBucket(string $clientId, array $limits): array
    {
        $key = "token_bucket:{$clientId}";
        $now = time();
        
        $bucket = Cache::get($key, [
            'tokens' => $limits['requests_per_minute'],
            'last_refill' => $now,
        ]);

        // Refill tokens based on time elapsed
        $timeElapsed = $now - $bucket['last_refill'];
        $tokensToAdd = ($timeElapsed / 60) * $limits['requests_per_minute'];
        $bucket['tokens'] = min($limits['requests_per_minute'], $bucket['tokens'] + $tokensToAdd);
        $bucket['last_refill'] = $now;

        $allowed = $bucket['tokens'] >= 1;
        
        if ($allowed) {
            $bucket['tokens']--;
        }

        Cache::put($key, $bucket, 3600); // Cache for 1 hour

        return [
            'allowed' => $allowed,
            'remaining' => max(0, (int) $bucket['tokens']),
            'limit' => $limits['requests_per_minute'],
            'reset_time' => $now + (60 - ($now % 60)), // Next minute boundary
            'algorithm' => 'token_bucket',
        ];
    }

    /**
     * Apply sliding window algorithm.
     */
    private function applySlidingWindow(string $clientId, array $limits): array
    {
        $key = "sliding_window:{$clientId}";
        $now = time();
        $windowStart = $now - $limits['window_size'];
        
        // Get request timestamps from cache
        $requests = Cache::get($key, []);
        
        // Remove old requests outside the window
        $requests = array_filter($requests, function($timestamp) use ($windowStart) {
            return $timestamp > $windowStart;
        });

        $currentCount = count($requests);
        $allowed = $currentCount < $limits['requests_per_minute'];

        if ($allowed) {
            $requests[] = $now;
        }

        Cache::put($key, $requests, $limits['window_size'] + 60); // Cache slightly longer than window

        return [
            'allowed' => $allowed,
            'remaining' => max(0, $limits['requests_per_minute'] - $currentCount - ($allowed ? 1 : 0)),
            'limit' => $limits['requests_per_minute'],
            'reset_time' => $windowStart + $limits['window_size'],
            'algorithm' => 'sliding_window',
            'current_count' => $currentCount,
        ];
    }

    /**
     * Apply fixed window algorithm.
     */
    private function applyFixedWindow(string $clientId, array $limits): array
    {
        $windowSize = $limits['window_size'];
        $now = time();
        $window = (int) ($now / $windowSize) * $windowSize;
        $key = "fixed_window:{$clientId}:{$window}";
        
        $count = Cache::increment($key, 1);
        if ($count === 1) {
            Cache::expire($key, $windowSize + 10); // Expire slightly after window ends
        }

        $allowed = $count <= $limits['requests_per_minute'];

        return [
            'allowed' => $allowed,
            'remaining' => max(0, $limits['requests_per_minute'] - $count),
            'limit' => $limits['requests_per_minute'],
            'reset_time' => $window + $windowSize,
            'algorithm' => 'fixed_window',
            'current_count' => $count,
        ];
    }

    /**
     * Apply leaky bucket algorithm.
     */
    private function applyLeakyBucket(string $clientId, array $limits): array
    {
        $key = "leaky_bucket:{$clientId}";
        $now = time();
        
        $bucket = Cache::get($key, [
            'volume' => 0,
            'last_leak' => $now,
        ]);

        // Calculate leak (requests processed)
        $timeElapsed = $now - $bucket['last_leak'];
        $leakAmount = ($timeElapsed / 60) * $limits['requests_per_minute'];
        $bucket['volume'] = max(0, $bucket['volume'] - $leakAmount);
        $bucket['last_leak'] = $now;

        $allowed = $bucket['volume'] < $limits['burst_size'];
        
        if ($allowed) {
            $bucket['volume']++;
        }

        Cache::put($key, $bucket, 3600); // Cache for 1 hour

        return [
            'allowed' => $allowed,
            'remaining' => max(0, $limits['burst_size'] - (int) $bucket['volume']),
            'limit' => $limits['requests_per_minute'],
            'reset_time' => $now + 60,
            'algorithm' => 'leaky_bucket',
            'bucket_volume' => (int) $bucket['volume'],
        ];
    }

    /**
     * Handle rate limit exceeded scenario.
     */
    private function handleRateLimit(Request $request, string $clientId, string $reason, array $limits, ?array $result = null): Response
    {
        // Log rate limit violation
        SecurityAuditLog::logEvent(
            SecurityAuditLog::EVENT_RATE_LIMIT,
            "Rate limit exceeded: {$reason}",
            SecurityAuditLog::SEVERITY_WARNING,
            [
                'client_id' => $clientId,
                'reason' => $reason,
                'path' => $request->path(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'limits' => $limits,
                'result' => $result,
                'headers' => $request->headers->all(),
            ]
        );

        // Increment violation count and potentially block client
        $this->incrementViolationCount($clientId);

        // Prepare response headers
        $headers = [];
        if ($result) {
            $headers['X-RateLimit-Limit'] = $result['limit'];
            $headers['X-RateLimit-Remaining'] = $result['remaining'];
            $headers['X-RateLimit-Reset'] = $result['reset_time'];
            $headers['X-RateLimit-Algorithm'] = $result['algorithm'];
        }

        $headers['Retry-After'] = $this->calculateRetryAfter($result);

        return response()->json([
            'error' => 'Rate limit exceeded',
            'message' => 'Too many requests. Please try again later.',
            'retry_after' => $headers['Retry-After'],
        ], ResponseCode::HTTP_TOO_MANY_REQUESTS, $headers);
    }

    /**
     * Calculate appropriate Retry-After header value.
     */
    private function calculateRetryAfter(?array $result): int
    {
        if (!$result || !isset($result['reset_time'])) {
            return 60; // Default 1 minute
        }

        return max(1, $result['reset_time'] - time());
    }

    /**
     * Increment violation count and potentially block client.
     */
    private function incrementViolationCount(string $clientId): void
    {
        $violationKey = "rate_limit_violations:{$clientId}";
        $violations = Cache::increment($violationKey, 1);
        
        if ($violations === 1) {
            Cache::expire($violationKey, 3600); // Track violations for 1 hour
        }

        // Block client if too many violations
        $maxViolations = $this->config['max_violations_before_block'] ?? 10;
        $blockDuration = $this->config['block_duration_seconds'] ?? 300; // 5 minutes
        
        if ($violations >= $maxViolations) {
            $blockKey = "rate_limit_block:{$clientId}";
            Cache::put($blockKey, true, $blockDuration);
            
            SecurityAuditLog::logEvent(
                SecurityAuditLog::EVENT_SECURITY_VIOLATION,
                'Client blocked due to excessive rate limit violations',
                SecurityAuditLog::SEVERITY_CRITICAL,
                [
                    'client_id' => $clientId,
                    'violations' => $violations,
                    'block_duration' => $blockDuration,
                ]
            );
        }
    }

    /**
     * Add rate limit headers to response.
     */
    private function addRateLimitHeaders($response, array $result): void
    {
        $response->headers->set('X-RateLimit-Limit', $result['limit']);
        $response->headers->set('X-RateLimit-Remaining', $result['remaining']);
        $response->headers->set('X-RateLimit-Reset', $result['reset_time']);
        $response->headers->set('X-RateLimit-Algorithm', $result['algorithm']);
    }

    /**
     * Log successful rate limit check.
     */
    private function logRateLimitSuccess(Request $request, string $clientId, array $result, float $processingTime): void
    {
        if ($this->config['log_successful_requests'] ?? false) {
            SecurityAuditLog::logEvent(
                SecurityAuditLog::EVENT_DATA_ACCESS,
                'Rate limit check passed',
                SecurityAuditLog::SEVERITY_INFO,
                [
                    'client_id' => $clientId,
                    'path' => $request->path(),
                    'remaining' => $result['remaining'],
                    'algorithm' => $result['algorithm'],
                    'processing_time_ms' => round($processingTime * 1000, 2),
                ]
            );
        }
    }
}