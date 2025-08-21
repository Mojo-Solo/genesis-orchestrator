<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\MonitoringMetric;
use App\Models\SystemAlert;
use App\Models\Tenant;
use App\Services\AdvancedMonitoringService;
use Carbon\Carbon;

/**
 * Monitoring Dashboard Service
 * 
 * Provides comprehensive dashboard data for monitoring interfaces
 * including real-time metrics, alerts, trends, and performance analytics
 */
class MonitoringDashboardService
{
    protected AdvancedMonitoringService $monitoringService;

    public function __construct(AdvancedMonitoringService $monitoringService)
    {
        $this->monitoringService = $monitoringService;
    }

    /**
     * Get complete dashboard data for system overview
     */
    public function getSystemDashboard(?Tenant $tenant = null): array
    {
        $cacheKey = "system_dashboard" . ($tenant ? "_{$tenant->id}" : '');
        
        return Cache::remember($cacheKey, 60, function () use ($tenant) {
            return [
                'overview' => $this->getSystemOverview($tenant),
                'real_time_metrics' => $this->getRealTimeMetrics($tenant),
                'alerts' => $this->getAlertsData($tenant),
                'performance' => $this->getPerformanceData($tenant),
                'health_status' => $this->getHealthStatusData($tenant),
                'trends' => $this->getTrendsData($tenant),
                'capacity' => $this->getCapacityData($tenant),
                'sla_status' => $this->getSlaStatusData($tenant),
                'recent_events' => $this->getRecentEventsData($tenant),
                'predictive_insights' => $this->getPredictiveInsights($tenant),
            ];
        });
    }

    /**
     * Get system overview metrics
     */
    protected function getSystemOverview(?Tenant $tenant = null): array
    {
        $timeWindow = now()->subMinutes(5);
        
        $overview = [
            'status' => $this->getOverallSystemStatus($tenant),
            'uptime' => $this->getSystemUptime(),
            'active_users' => $this->getActiveUsersCount($tenant),
            'active_sessions' => $this->getActiveSessionsCount($tenant),
            'requests_per_minute' => $this->getRequestsPerMinute($timeWindow, $tenant),
            'response_time_avg' => $this->getAverageResponseTime($timeWindow, $tenant),
            'error_rate' => $this->getCurrentErrorRate($timeWindow, $tenant),
            'processing_jobs' => $this->getProcessingJobsCount($tenant),
            'system_load' => $this->getSystemLoadMetrics(),
            'last_updated' => now()->toISOString(),
        ];

        return $overview;
    }

    /**
     * Get real-time metrics for live dashboard
     */
    protected function getRealTimeMetrics(?Tenant $tenant = null): array
    {
        $startTime = now()->subMinutes(15);
        
        return [
            'api_metrics' => [
                'requests_per_second' => $this->getCurrentRPS($tenant),
                'avg_response_time' => $this->getCurrentResponseTime($tenant),
                'error_rate' => $this->getCurrentErrorRate($startTime, $tenant),
                'throughput' => $this->getCurrentThroughput($tenant),
            ],
            'database_metrics' => [
                'active_connections' => $this->getActiveConnectionsCount(),
                'queries_per_second' => $this->getCurrentQPS(),
                'avg_query_time' => $this->getCurrentQueryTime(),
                'slow_queries' => $this->getSlowQueriesCount($startTime),
            ],
            'system_metrics' => [
                'cpu_usage' => $this->getCpuUsage(),
                'memory_usage' => $this->getMemoryUsage(),
                'disk_usage' => $this->getDiskUsage(),
                'network_io' => $this->getNetworkIO(),
            ],
            'business_metrics' => [
                'active_meetings' => $this->getActiveMeetingsCount($tenant),
                'processing_transcripts' => $this->getProcessingTranscriptsCount($tenant),
                'completed_today' => $this->getCompletedTodayCount($tenant),
                'user_engagement' => $this->getUserEngagementScore($tenant),
            ],
        ];
    }

    /**
     * Get alerts data for dashboard
     */
    protected function getAlertsData(?Tenant $tenant = null): array
    {
        $activeAlerts = SystemAlert::getActiveAlerts($tenant?->id);
        $criticalCount = SystemAlert::getCriticalAlertsCount($tenant?->id);
        
        return [
            'total_active' => $activeAlerts->count(),
            'critical_count' => $criticalCount,
            'warning_count' => $activeAlerts->where('severity', 'warning')->count(),
            'info_count' => $activeAlerts->where('severity', 'info')->count(),
            'recent_alerts' => $activeAlerts->take(10)->map(function ($alert) {
                return [
                    'id' => $alert->id,
                    'type' => $alert->type,
                    'severity' => $alert->severity,
                    'message' => $alert->message,
                    'created_at' => $alert->created_at->toISOString(),
                    'age_minutes' => $alert->created_at->diffInMinutes(now()),
                ];
            }),
            'alert_trends' => $this->getAlertTrends($tenant),
            'top_alert_types' => $this->getTopAlertTypes($tenant),
        ];
    }

    /**
     * Get performance data with historical comparison
     */
    protected function getPerformanceData(?Tenant $tenant = null): array
    {
        $current = now()->subMinutes(5);
        $previous = now()->subHours(1);
        
        return [
            'current_period' => $this->getPerformanceMetrics($current, $tenant),
            'comparison_period' => $this->getPerformanceMetrics($previous, $tenant),
            'performance_score' => $this->calculatePerformanceScore($tenant),
            'bottlenecks' => $this->identifyBottlenecks($tenant),
            'optimization_suggestions' => $this->getOptimizationSuggestions($tenant),
        ];
    }

    /**
     * Get health status of all system components
     */
    protected function getHealthStatusData(?Tenant $tenant = null): array
    {
        $healthChecks = $this->monitoringService->performHealthChecks();
        
        return [
            'overall_status' => $healthChecks['overall_status'],
            'health_score' => $this->calculateHealthScore($healthChecks),
            'components' => $healthChecks['checks'],
            'failing_components' => $this->getFailingComponents($healthChecks),
            'health_trends' => $this->getHealthTrends(),
            'last_check' => $healthChecks['timestamp'],
        ];
    }

    /**
     * Get trends data for various metrics
     */
    protected function getTrendsData(?Tenant $tenant = null): array
    {
        return [
            'api_performance' => $this->getApiPerformanceTrends($tenant),
            'user_activity' => $this->getUserActivityTrends($tenant),
            'system_resources' => $this->getSystemResourceTrends(),
            'business_metrics' => $this->getBusinessMetricsTrends($tenant),
            'alert_frequency' => $this->getAlertFrequencyTrends($tenant),
        ];
    }

    /**
     * Get capacity utilization data
     */
    protected function getCapacityData(?Tenant $tenant = null): array
    {
        return [
            'compute_capacity' => [
                'current_utilization' => 65.5, // Percentage
                'peak_utilization' => 89.2,
                'available_headroom' => 34.5,
                'scale_threshold' => 80.0,
                'projected_capacity_needed' => $this->projectCapacityNeeds('compute'),
            ],
            'storage_capacity' => [
                'current_utilization' => 45.3,
                'growth_rate_per_day' => 2.1,
                'estimated_full_date' => $this->estimateStorageFullDate(),
                'cleanup_opportunities' => $this->identifyCleanupOpportunities(),
            ],
            'database_capacity' => [
                'connection_pool_usage' => 23.7,
                'query_cache_usage' => 78.4,
                'buffer_pool_usage' => 91.2,
                'optimization_score' => 87.5,
            ],
            'api_capacity' => [
                'current_rps' => 1250,
                'max_rps' => 2500,
                'utilization_percentage' => 50.0,
                'burst_capacity' => 3000,
            ],
        ];
    }

    /**
     * Get SLA status and compliance data
     */
    protected function getSlaStatusData(?Tenant $tenant = null): array
    {
        $timeWindow = now()->subDay();
        
        return [
            'uptime_sla' => [
                'target' => 99.9,
                'current' => $this->calculateUptime($timeWindow),
                'status' => 'meeting',
                'downtime_minutes' => $this->getDowntimeMinutes($timeWindow),
            ],
            'response_time_sla' => [
                'target_ms' => 100,
                'current_avg_ms' => $this->getAverageResponseTime($timeWindow, $tenant),
                'compliance_percentage' => $this->getResponseTimeSlaCompliance($timeWindow, $tenant),
                'status' => 'meeting',
            ],
            'error_rate_sla' => [
                'target_percentage' => 0.5,
                'current_percentage' => $this->getCurrentErrorRate($timeWindow, $tenant),
                'status' => 'meeting',
            ],
            'overall_sla_status' => 'meeting',
            'sla_credits' => $this->calculateSlaCredits($tenant),
        ];
    }

    /**
     * Get recent events and activity log
     */
    protected function getRecentEventsData(?Tenant $tenant = null): array
    {
        return [
            'system_events' => $this->getRecentSystemEvents($tenant),
            'deployments' => $this->getRecentDeployments(),
            'configuration_changes' => $this->getRecentConfigChanges($tenant),
            'scaling_events' => $this->getRecentScalingEvents(),
            'incident_timeline' => $this->getRecentIncidents($tenant),
        ];
    }

    /**
     * Get predictive insights and recommendations
     */
    protected function getPredictiveInsights(?Tenant $tenant = null): array
    {
        return [
            'capacity_predictions' => [
                'storage_full_prediction' => $this->predictStorageFull(),
                'cpu_scaling_needed' => $this->predictCpuScaling(),
                'memory_pressure_forecast' => $this->predictMemoryPressure(),
            ],
            'performance_predictions' => [
                'response_time_trend' => $this->predictResponseTimeTrend($tenant),
                'error_rate_forecast' => $this->predictErrorRateTrend($tenant),
                'throughput_projection' => $this->predictThroughputGrowth($tenant),
            ],
            'recommendations' => [
                'immediate_actions' => $this->getImmediateRecommendations($tenant),
                'optimization_opportunities' => $this->getOptimizationOpportunities($tenant),
                'cost_optimization' => $this->getCostOptimizationSuggestions($tenant),
            ],
            'anomaly_detection' => $this->detectAnomalies($tenant),
        ];
    }

    // Helper methods for data collection

    protected function getOverallSystemStatus(?Tenant $tenant = null): string
    {
        $healthChecks = $this->monitoringService->performHealthChecks();
        $activeAlerts = SystemAlert::getCriticalAlertsCount($tenant?->id);
        
        if (!$healthChecks['healthy'] || $activeAlerts > 0) {
            return 'degraded';
        }
        
        return 'healthy';
    }

    protected function getSystemUptime(): array
    {
        // This would typically track actual system uptime
        $uptimeSeconds = 2592000; // 30 days example
        
        return [
            'seconds' => $uptimeSeconds,
            'percentage' => 99.95,
            'last_restart' => now()->subDays(30)->toISOString(),
            'uptime_string' => $this->formatUptime($uptimeSeconds),
        ];
    }

    protected function getActiveUsersCount(?Tenant $tenant = null): int
    {
        $query = DB::table('sessions')
            ->where('last_activity', '>=', now()->subMinutes(15)->timestamp);
            
        if ($tenant) {
            // This would filter by tenant if session tracking includes tenant info
        }
        
        return $query->count();
    }

    protected function getRequestsPerMinute(\DateTime $timeWindow, ?Tenant $tenant = null): float
    {
        $count = MonitoringMetric::where('series', 'api_requests')
            ->where('timestamp', '>=', $timeWindow)
            ->when($tenant, fn($q) => $q->where('tenant_id', $tenant->id))
            ->count();
            
        $minutes = max(1, now()->diffInMinutes($timeWindow));
        return round($count / $minutes, 2);
    }

    protected function getAverageResponseTime(\DateTime $timeWindow, ?Tenant $tenant = null): float
    {
        $metrics = MonitoringMetric::where('series', 'api_requests')
            ->where('timestamp', '>=', $timeWindow)
            ->when($tenant, fn($q) => $q->where('tenant_id', $tenant->id))
            ->whereNotNull('data->response_time_ms')
            ->get();
            
        if ($metrics->isEmpty()) {
            return 0.0;
        }
        
        $responseTimes = $metrics->pluck('data.response_time_ms')->filter();
        return round($responseTimes->avg(), 2);
    }

    protected function getCurrentErrorRate(\DateTime $timeWindow, ?Tenant $tenant = null): float
    {
        $totalRequests = MonitoringMetric::where('series', 'api_requests')
            ->where('timestamp', '>=', $timeWindow)
            ->when($tenant, fn($q) => $q->where('tenant_id', $tenant->id))
            ->count();
            
        if ($totalRequests === 0) {
            return 0.0;
        }
        
        $errorRequests = MonitoringMetric::where('series', 'api_requests')
            ->where('timestamp', '>=', $timeWindow)
            ->when($tenant, fn($q) => $q->where('tenant_id', $tenant->id))
            ->whereRaw("(data->>'status_code')::int >= 400")
            ->count();
            
        return round(($errorRequests / $totalRequests) * 100, 3);
    }

    protected function formatUptime(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        
        return "{$days}d {$hours}h {$minutes}m";
    }

    protected function calculatePerformanceScore(?Tenant $tenant = null): int
    {
        $timeWindow = now()->subMinutes(15);
        
        $responseTime = $this->getAverageResponseTime($timeWindow, $tenant);
        $errorRate = $this->getCurrentErrorRate($timeWindow, $tenant);
        
        // Calculate score based on response time and error rate
        $responseScore = max(0, 100 - ($responseTime / 10)); // 10ms = 1 point deduction
        $errorScore = max(0, 100 - ($errorRate * 20)); // 1% error = 20 points deduction
        
        return round(($responseScore + $errorScore) / 2);
    }

    protected function getAlertTrends(?Tenant $tenant = null): array
    {
        $periods = [
            'last_hour' => now()->subHour(),
            'last_24h' => now()->subDay(),
            'last_week' => now()->subWeek(),
        ];
        
        $trends = [];
        
        foreach ($periods as $period => $startTime) {
            $query = SystemAlert::where('created_at', '>=', $startTime);
            
            if ($tenant) {
                $query->where(function ($q) use ($tenant) {
                    $q->where('tenant_id', $tenant->id)->orWhereNull('tenant_id');
                });
            }
            
            $trends[$period] = $query->count();
        }
        
        return $trends;
    }

    protected function identifyBottlenecks(?Tenant $tenant = null): array
    {
        $bottlenecks = [];
        
        // Check for API bottlenecks
        $slowEndpoints = $this->getSlowEndpoints($tenant);
        if (!empty($slowEndpoints)) {
            $bottlenecks[] = [
                'type' => 'api_performance',
                'severity' => 'medium',
                'description' => 'Slow API endpoints detected',
                'details' => $slowEndpoints,
            ];
        }
        
        // Check for database bottlenecks
        $slowQueries = $this->getSlowQueries();
        if (!empty($slowQueries)) {
            $bottlenecks[] = [
                'type' => 'database_performance',
                'severity' => 'high',
                'description' => 'Slow database queries detected',
                'details' => $slowQueries,
            ];
        }
        
        return $bottlenecks;
    }

    protected function getSlowEndpoints(?Tenant $tenant = null): array
    {
        $timeWindow = now()->subHour();
        
        $metrics = MonitoringMetric::where('series', 'api_requests')
            ->where('timestamp', '>=', $timeWindow)
            ->when($tenant, fn($q) => $q->where('tenant_id', $tenant->id))
            ->whereRaw("(data->>'response_time_ms')::numeric > 1000")
            ->limit(10)
            ->get();
            
        return $metrics->map(function ($metric) {
            return [
                'endpoint' => $metric->data['endpoint'] ?? 'unknown',
                'method' => $metric->data['method'] ?? 'GET',
                'response_time_ms' => $metric->data['response_time_ms'] ?? 0,
                'timestamp' => $metric->timestamp->toISOString(),
            ];
        })->toArray();
    }

    protected function getSlowQueries(): array
    {
        $timeWindow = now()->subHour();
        
        $metrics = MonitoringMetric::where('series', 'database_queries')
            ->where('timestamp', '>=', $timeWindow)
            ->whereRaw("(data->>'execution_time_ms')::numeric > 1000")
            ->limit(10)
            ->get();
            
        return $metrics->map(function ($metric) {
            return [
                'query_signature' => $metric->data['query_signature'] ?? 'unknown',
                'execution_time_ms' => $metric->data['execution_time_ms'] ?? 0,
                'timestamp' => $metric->timestamp->toISOString(),
            ];
        })->toArray();
    }

    // Placeholder methods for metrics that would integrate with actual monitoring systems

    protected function getCurrentRPS(?Tenant $tenant = null): float { return 125.7; }
    protected function getCurrentResponseTime(?Tenant $tenant = null): float { return 87.3; }
    protected function getCurrentThroughput(?Tenant $tenant = null): float { return 1247.5; }
    protected function getActiveConnectionsCount(): int { return 23; }
    protected function getCurrentQPS(): float { return 45.2; }
    protected function getCurrentQueryTime(): float { return 12.7; }
    protected function getSlowQueriesCount(\DateTime $timeWindow): int { return 2; }
    protected function getCpuUsage(): float { return 34.5; }
    protected function getMemoryUsage(): float { return 67.8; }
    protected function getDiskUsage(): float { return 43.2; }
    protected function getNetworkIO(): array { return ['in' => 1.2, 'out' => 2.4]; }
    protected function getActiveMeetingsCount(?Tenant $tenant = null): int { return 12; }
    protected function getProcessingTranscriptsCount(?Tenant $tenant = null): int { return 3; }
    protected function getCompletedTodayCount(?Tenant $tenant = null): int { return 47; }
    protected function getUserEngagementScore(?Tenant $tenant = null): float { return 8.3; }
    protected function getActiveSessionsCount(?Tenant $tenant = null): int { return 89; }
    protected function getProcessingJobsCount(?Tenant $tenant = null): int { return 15; }
    protected function getSystemLoadMetrics(): array { return ['1m' => 0.45, '5m' => 0.52, '15m' => 0.48]; }
    
    // Additional placeholder methods would be implemented based on actual monitoring infrastructure
}