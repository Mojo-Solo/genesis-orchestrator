<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EnhancedRateLimitService;
use App\Services\CircuitBreakerService;
use App\Services\ThreatDetectionService;
use App\Models\Tenant;
use App\Models\SecurityAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;

/**
 * Rate Limiting Dashboard and Management Controller
 * 
 * Provides comprehensive API endpoints for monitoring and managing
 * the advanced rate limiting system, circuit breakers, and threat detection.
 */
class RateLimitController extends Controller
{
    private EnhancedRateLimitService $rateLimitService;
    private CircuitBreakerService $circuitBreaker;
    private ThreatDetectionService $threatDetector;

    public function __construct(
        EnhancedRateLimitService $rateLimitService,
        CircuitBreakerService $circuitBreaker,
        ThreatDetectionService $threatDetector
    ) {
        $this->rateLimitService = $rateLimitService;
        $this->circuitBreaker = $circuitBreaker;
        $this->threatDetector = $threatDetector;
    }

    /**
     * Get comprehensive rate limiting dashboard data
     */
    public function dashboard(Request $request): JsonResponse
    {
        $timeRange = $request->get('timeRange', 3600); // Default 1 hour
        
        $data = [
            'overview' => $this->getOverviewMetrics($timeRange),
            'rate_limits' => $this->getRateLimitMetrics($timeRange),
            'circuit_breakers' => $this->circuitBreaker->getAllMetrics(),
            'threat_detection' => $this->threatDetector->getMetrics($timeRange),
            'tenant_usage' => $this->getTenantUsageMetrics($timeRange),
            'system_health' => $this->getSystemHealthMetrics(),
            'alerts' => $this->getActiveAlerts(),
            'recent_blocks' => $this->getRecentBlocks($timeRange),
            'performance' => $this->getPerformanceMetrics($timeRange)
        ];
        
        return response()->json([
            'success' => true,
            'data' => $data,
            'timestamp' => time(),
            'time_range' => $timeRange
        ]);
    }

    /**
     * Get overview metrics for the dashboard
     */
    private function getOverviewMetrics(int $timeRange): array
    {
        $now = time();
        $start = $now - $timeRange;
        
        // Get rate limit violations
        $violations = SecurityAuditLog::where('event_type', SecurityAuditLog::EVENT_RATE_LIMIT)
            ->where('created_at', '>=', Carbon::createFromTimestamp($start))
            ->count();
        
        // Get total requests (approximation from rate limit checks)
        $totalRequests = $this->getTotalRequests($timeRange);
        
        // Calculate block rate
        $blockRate = $totalRequests > 0 ? ($violations / $totalRequests) * 100 : 0;
        
        // Get threat level
        $threatStatus = $this->threatDetector->getThreatStatus();
        
        // Get circuit breaker health
        $circuitHealth = $this->circuitBreaker->healthCheck();
        
        return [
            'total_requests' => $totalRequests,
            'blocked_requests' => $violations,
            'block_rate' => round($blockRate, 2),
            'threat_level' => $threatStatus['status'],
            'circuit_breaker_status' => $circuitHealth['status'],
            'active_tenants' => Tenant::active()->count(),
            'system_load' => $this->getSystemLoad(),
            'uptime' => $this->getSystemUptime()
        ];
    }

    /**
     * Get detailed rate limiting metrics
     */
    private function getRateLimitMetrics(int $timeRange): array
    {
        $metrics = [
            'by_algorithm' => $this->getMetricsByAlgorithm($timeRange),
            'by_scope' => $this->getMetricsByScope($timeRange),
            'by_tier' => $this->getMetricsByTier($timeRange),
            'by_endpoint' => $this->getMetricsByEndpoint($timeRange),
            'queue_stats' => $this->getQueueStatistics(),
            'temporal_distribution' => $this->getTemporalDistribution($timeRange)
        ];
        
        return $metrics;
    }

    /**
     * Get tenant usage metrics
     */
    private function getTenantUsageMetrics(int $timeRange): array
    {
        $tenants = Tenant::active()->get();
        $usage = [];
        
        foreach ($tenants as $tenant) {
            $usage[] = [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'tier' => $tenant->tier,
                'current_usage' => [
                    'api_calls' => $this->getTenantApiCalls($tenant->id, $timeRange),
                    'rate_limit_hits' => $this->getTenantRateLimitHits($tenant->id, $timeRange),
                    'queue_usage' => $this->getTenantQueueUsage($tenant->id)
                ],
                'limits' => [
                    'max_api_calls_per_minute' => $tenant->max_api_calls_per_minute,
                    'max_orchestration_runs_per_month' => $tenant->max_orchestration_runs_per_month,
                    'max_tokens_per_month' => $tenant->max_tokens_per_month
                ],
                'usage_percentage' => [
                    'api_calls' => $this->calculateUsagePercentage($tenant, 'api_calls', $timeRange),
                    'orchestration_runs' => $tenant->getUsagePercentage('orchestration_runs'),
                    'tokens' => $tenant->getUsagePercentage('tokens')
                ]
            ];
        }
        
        return $usage;
    }

    /**
     * Get system health metrics
     */
    private function getSystemHealthMetrics(): array
    {
        return [
            'redis_connection' => $this->checkRedisHealth(),
            'database_connection' => $this->checkDatabaseHealth(),
            'circuit_breakers' => $this->circuitBreaker->healthCheck(),
            'threat_detection' => $this->threatDetector->getThreatStatus(),
            'memory_usage' => $this->getMemoryUsage(),
            'cpu_usage' => $this->getSystemLoad(),
            'disk_usage' => $this->getDiskUsage(),
            'response_times' => $this->getAverageResponseTimes()
        ];
    }

    /**
     * Get active alerts
     */
    private function getActiveAlerts(): array
    {
        $alerts = [];
        
        // Check for high block rate
        $blockRate = $this->getBlockRate(300); // Last 5 minutes
        if ($blockRate > 10) {
            $alerts[] = [
                'type' => 'high_block_rate',
                'severity' => $blockRate > 25 ? 'critical' : 'warning',
                'message' => "High block rate: {$blockRate}%",
                'timestamp' => time()
            ];
        }
        
        // Check circuit breaker status
        $circuitHealth = $this->circuitBreaker->healthCheck();
        if ($circuitHealth['status'] !== 'healthy') {
            $alerts[] = [
                'type' => 'circuit_breaker',
                'severity' => $circuitHealth['status'] === 'unhealthy' ? 'critical' : 'warning',
                'message' => "Circuit breaker status: {$circuitHealth['status']}",
                'timestamp' => time()
            ];
        }
        
        // Check threat status
        $threatStatus = $this->threatDetector->getThreatStatus();
        if ($threatStatus['status'] !== 'normal') {
            $alerts[] = [
                'type' => 'threat_detection',
                'severity' => $threatStatus['status'] === 'critical' ? 'critical' : 'warning',
                'message' => "Threat level: {$threatStatus['status']}",
                'timestamp' => time()
            ];
        }
        
        // Check system load
        $systemLoad = $this->getSystemLoad();
        if ($systemLoad > 0.8) {
            $alerts[] = [
                'type' => 'high_system_load',
                'severity' => $systemLoad > 0.9 ? 'critical' : 'warning',
                'message' => "High system load: " . round($systemLoad * 100) . "%",
                'timestamp' => time()
            ];
        }
        
        return $alerts;
    }

    /**
     * Get recent blocks/violations
     */
    private function getRecentBlocks(int $timeRange): array
    {
        $blocks = SecurityAuditLog::where('event_type', SecurityAuditLog::EVENT_RATE_LIMIT)
            ->where('severity', '>=', SecurityAuditLog::SEVERITY_WARNING)
            ->where('created_at', '>=', Carbon::createFromTimestamp(time() - $timeRange))
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();
        
        return $blocks->map(function ($block) {
            return [
                'id' => $block->id,
                'timestamp' => $block->created_at->timestamp,
                'client_id' => $block->event_data['client_id'] ?? 'unknown',
                'reason' => $block->event_data['reason'] ?? 'rate_limit',
                'scope' => $block->event_data['scope'] ?? 'unknown',
                'ip' => $block->event_data['ip'] ?? 'unknown',
                'path' => $block->event_data['path'] ?? 'unknown',
                'severity' => $block->severity
            ];
        })->toArray();
    }

    /**
     * Get performance metrics
     */
    private function getPerformanceMetrics(int $timeRange): array
    {
        return [
            'average_response_time' => $this->getAverageResponseTimes(),
            'throughput' => $this->getThroughputMetrics($timeRange),
            'error_rates' => $this->getErrorRates($timeRange),
            'cache_hit_ratio' => $this->getCacheHitRatio(),
            'queue_performance' => $this->getQueuePerformanceMetrics()
        ];
    }

    /**
     * Get real-time rate limit status for specific client
     */
    public function clientStatus(Request $request): JsonResponse
    {
        $clientId = $request->get('client_id');
        if (!$clientId) {
            return response()->json(['error' => 'client_id required'], 400);
        }
        
        $status = [
            'client_id' => $clientId,
            'current_limits' => $this->getCurrentLimits($clientId),
            'usage' => $this->getCurrentUsage($clientId),
            'blocked' => $this->isClientBlocked($clientId),
            'threat_level' => $this->getThreatLevel($clientId),
            'queue_position' => $this->getQueuePosition($clientId),
            'last_request' => $this->getLastRequestTime($clientId)
        ];
        
        return response()->json(['success' => true, 'data' => $status]);
    }

    /**
     * Update rate limiting configuration
     */
    public function updateConfig(Request $request): JsonResponse
    {
        $request->validate([
            'tier' => 'sometimes|string|in:free,starter,professional,enterprise',
            'endpoint' => 'sometimes|string',
            'limits' => 'sometimes|array',
            'limits.requests_per_minute' => 'sometimes|integer|min:1',
            'limits.burst_size' => 'sometimes|integer|min:1',
            'circuit_breaker' => 'sometimes|array'
        ]);
        
        try {
            // Update circuit breaker configuration
            if ($request->has('circuit_breaker')) {
                $this->circuitBreaker->updateConfiguration($request->input('circuit_breaker'));
            }
            
            // Update rate limiting configuration
            if ($request->has('limits')) {
                $this->updateRateLimitConfig($request->input('limits'));
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Configuration updated successfully'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to update configuration: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Manually block/unblock a client
     */
    public function manageClient(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => 'required|string',
            'action' => 'required|string|in:block,unblock,reset',
            'duration' => 'sometimes|integer|min:1',
            'reason' => 'sometimes|string'
        ]);
        
        $clientId = $request->input('client_id');
        $action = $request->input('action');
        $duration = $request->input('duration', 300); // 5 minutes default
        $reason = $request->input('reason', 'manual_action');
        
        try {
            switch ($action) {
                case 'block':
                    Cache::put("manual_block:{$clientId}", true, $duration);
                    SecurityAuditLog::logEvent(
                        SecurityAuditLog::EVENT_SECURITY_VIOLATION,
                        "Client manually blocked: {$reason}",
                        SecurityAuditLog::SEVERITY_WARNING,
                        ['client_id' => $clientId, 'duration' => $duration, 'reason' => $reason]
                    );
                    break;
                    
                case 'unblock':
                    Cache::forget("manual_block:{$clientId}");
                    Cache::forget("rate_limit_block:{$clientId}");
                    SecurityAuditLog::logEvent(
                        SecurityAuditLog::EVENT_DATA_ACCESS,
                        "Client manually unblocked: {$reason}",
                        SecurityAuditLog::SEVERITY_INFO,
                        ['client_id' => $clientId, 'reason' => $reason]
                    );
                    break;
                    
                case 'reset':
                    $this->resetClientCounters($clientId);
                    SecurityAuditLog::logEvent(
                        SecurityAuditLog::EVENT_DATA_ACCESS,
                        "Client counters reset: {$reason}",
                        SecurityAuditLog::SEVERITY_INFO,
                        ['client_id' => $clientId, 'reason' => $reason]
                    );
                    break;
            }
            
            return response()->json([
                'success' => true,
                'message' => "Client {$action} action completed"
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to manage client: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get rate limiting statistics for export
     */
    public function exportStats(Request $request): JsonResponse
    {
        $timeRange = $request->get('timeRange', 86400); // 24 hours default
        $format = $request->get('format', 'json');
        
        $stats = [
            'metadata' => [
                'export_time' => time(),
                'time_range' => $timeRange,
                'format' => $format
            ],
            'overview' => $this->getOverviewMetrics($timeRange),
            'detailed_metrics' => $this->getRateLimitMetrics($timeRange),
            'tenant_breakdown' => $this->getTenantUsageMetrics($timeRange),
            'security_events' => $this->getSecurityEvents($timeRange),
            'performance_data' => $this->getPerformanceMetrics($timeRange)
        ];
        
        if ($format === 'csv') {
            // Convert to CSV format
            $csv = $this->convertToCSV($stats);
            return response($csv, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="rate_limit_stats.csv"'
            ]);
        }
        
        return response()->json(['success' => true, 'data' => $stats]);
    }

    // Helper methods for metrics collection
    
    private function getTotalRequests(int $timeRange): int
    {
        // Estimate from rate limit check logs
        return SecurityAuditLog::where('event_type', SecurityAuditLog::EVENT_DATA_ACCESS)
            ->where('description', 'like', '%rate limit check%')
            ->where('created_at', '>=', Carbon::createFromTimestamp(time() - $timeRange))
            ->count() * 10; // Approximate multiplier
    }
    
    private function getSystemLoad(): float
    {
        if (!function_exists('sys_getloadavg')) {
            return 0.5;
        }
        
        $load = sys_getloadavg();
        $cpuCount = $this->getCpuCount();
        
        return min(1.0, $load[0] / $cpuCount);
    }
    
    private function getCpuCount(): int
    {
        if (is_file('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            return substr_count($cpuinfo, 'processor') ?: 1;
        }
        
        return 1;
    }
    
    private function getSystemUptime(): int
    {
        if (is_file('/proc/uptime')) {
            $uptime = file_get_contents('/proc/uptime');
            return (int) floatval(explode(' ', $uptime)[0]);
        }
        
        return 0;
    }
    
    private function checkRedisHealth(): array
    {
        try {
            Redis::ping();
            return ['status' => 'healthy', 'response_time' => 0];
        } catch (\Exception $e) {
            return ['status' => 'unhealthy', 'error' => $e->getMessage()];
        }
    }
    
    private function checkDatabaseHealth(): array
    {
        try {
            $start = microtime(true);
            \DB::select('SELECT 1');
            $responseTime = (microtime(true) - $start) * 1000;
            
            return ['status' => 'healthy', 'response_time' => round($responseTime, 2)];
        } catch (\Exception $e) {
            return ['status' => 'unhealthy', 'error' => $e->getMessage()];
        }
    }
    
    private function getMemoryUsage(): array
    {
        return [
            'used' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => ini_get('memory_limit')
        ];
    }
    
    private function getDiskUsage(): array
    {
        $total = disk_total_space('.');
        $free = disk_free_space('.');
        $used = $total - $free;
        
        return [
            'total' => $total,
            'used' => $used,
            'free' => $free,
            'percentage' => $total > 0 ? round(($used / $total) * 100, 2) : 0
        ];
    }
    
    private function getAverageResponseTimes(): array
    {
        // This would typically come from application metrics
        return [
            'p50' => 50,  // 50ms
            'p95' => 200, // 200ms
            'p99' => 500  // 500ms
        ];
    }
    
    private function getBlockRate(int $timeRange): float
    {
        $totalRequests = $this->getTotalRequests($timeRange);
        $violations = SecurityAuditLog::where('event_type', SecurityAuditLog::EVENT_RATE_LIMIT)
            ->where('created_at', '>=', Carbon::createFromTimestamp(time() - $timeRange))
            ->count();
            
        return $totalRequests > 0 ? ($violations / $totalRequests) * 100 : 0;
    }
    
    private function resetClientCounters(string $clientId): void
    {
        $patterns = [
            "rate_limit:user:{$clientId}",
            "rate_limit:*:{$clientId}",
            "token_bucket:{$clientId}",
            "sliding_window:{$clientId}",
            "fixed_window:{$clientId}*",
            "leaky_bucket:{$clientId}",
            "threat:*:{$clientId}*"
        ];
        
        foreach ($patterns as $pattern) {
            $keys = Redis::keys($pattern);
            if (!empty($keys)) {
                Redis::del($keys);
            }
        }
    }
    
    // Additional helper methods would be implemented here for specific metrics...
}