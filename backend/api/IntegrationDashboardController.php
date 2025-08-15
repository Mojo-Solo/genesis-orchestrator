<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SSOIntegrationService;
use App\Services\APIMarketplaceService;
use App\Services\WebhookDeliveryService;
use App\Services\PluginArchitectureService;
use App\Services\DataSynchronizationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class IntegrationDashboardController extends Controller
{
    protected $ssoService;
    protected $marketplaceService;
    protected $webhookService;
    protected $pluginService;
    protected $syncService;

    public function __construct(
        SSOIntegrationService $ssoService,
        APIMarketplaceService $marketplaceService,
        WebhookDeliveryService $webhookService,
        PluginArchitectureService $pluginService,
        DataSynchronizationService $syncService
    ) {
        $this->ssoService = $ssoService;
        $this->marketplaceService = $marketplaceService;
        $this->webhookService = $webhookService;
        $this->pluginService = $pluginService;
        $this->syncService = $syncService;
        
        $this->middleware(['tenant.isolation']);
    }

    /**
     * Get integration dashboard overview
     */
    public function getDashboardOverview(Request $request): JsonResponse
    {
        try {
            $tenantId = $request->header('X-Tenant-ID');
            
            $overview = [
                'summary' => $this->getSummaryStats($tenantId),
                'sso_status' => $this->getSSOStatus($tenantId),
                'api_connectors' => $this->getConnectorStats($tenantId),
                'webhooks' => $this->getWebhookStats($tenantId),
                'plugins' => $this->getPluginStats($tenantId),
                'sync_jobs' => $this->getSyncJobStats($tenantId),
                'health_overview' => $this->getHealthOverview($tenantId),
                'recent_activity' => $this->getRecentActivity($tenantId),
            ];

            return response()->json([
                'status' => 'success',
                'data' => $overview,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get integration dashboard overview', [
                'tenant_id' => $request->header('X-Tenant-ID'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to get dashboard overview',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get integration analytics
     */
    public function getIntegrationAnalytics(Request $request): JsonResponse
    {
        try {
            $tenantId = $request->header('X-Tenant-ID');
            $timeRange = $this->getTimeRangeFromRequest($request);
            
            $analytics = [
                'usage_trends' => $this->getUsageTrends($tenantId, $timeRange),
                'performance_metrics' => $this->getPerformanceMetrics($tenantId, $timeRange),
                'error_analysis' => $this->getErrorAnalysis($tenantId, $timeRange),
                'cost_breakdown' => $this->getCostBreakdown($tenantId, $timeRange),
                'integration_adoption' => $this->getIntegrationAdoption($tenantId),
            ];

            return response()->json([
                'status' => 'success',
                'data' => $analytics,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get integration analytics', [
                'tenant_id' => $request->header('X-Tenant-ID'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to get integration analytics',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get integration health status
     */
    public function getIntegrationHealth(Request $request): JsonResponse
    {
        try {
            $tenantId = $request->header('X-Tenant-ID');
            
            $health = [
                'overall_status' => $this->getOverallHealthStatus($tenantId),
                'component_health' => [
                    'sso' => $this->getComponentHealth($tenantId, 'sso'),
                    'api_connectors' => $this->getComponentHealth($tenantId, 'api_connector'),
                    'webhooks' => $this->getComponentHealth($tenantId, 'webhook'),
                    'plugins' => $this->getComponentHealth($tenantId, 'plugin'),
                    'sync_jobs' => $this->getComponentHealth($tenantId, 'sync'),
                ],
                'alerts' => $this->getActiveAlerts($tenantId),
                'recommendations' => $this->getHealthRecommendations($tenantId),
            ];

            return response()->json([
                'status' => 'success',
                'data' => $health,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get integration health', [
                'tenant_id' => $request->header('X-Tenant-ID'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to get integration health',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper methods for data aggregation
     */
    protected function getSummaryStats(string $tenantId): array
    {
        // Get counts from various tables
        $apiCalls = DB::table('api_call_metrics')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->count();

        $webhookDeliveries = DB::table('webhook_deliveries')
            ->join('webhook_endpoints', 'webhook_deliveries.webhook_id', '=', 'webhook_endpoints.id')
            ->where('webhook_endpoints.tenant_id', $tenantId)
            ->where('webhook_deliveries.created_at', '>=', Carbon::now()->subDays(30))
            ->count();

        $activeConnectors = DB::table('tenant_connector_configurations')
            ->where('tenant_id', $tenantId)
            ->count();

        $activePlugins = DB::table('tenant_plugins')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->count();

        $syncJobs = DB::table('sync_jobs')
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->count();

        return [
            'total_api_calls_30d' => $apiCalls,
            'total_webhook_deliveries_30d' => $webhookDeliveries,
            'active_connectors' => $activeConnectors,
            'active_plugins' => $activePlugins,
            'active_sync_jobs' => $syncJobs,
        ];
    }

    protected function getSSOStatus(string $tenantId): array
    {
        // Check if SSO is configured and working
        $tenant = DB::table('tenants')->where('id', $tenantId)->first();
        
        return [
            'enabled' => $tenant ? $tenant->sso_enabled : false,
            'configured_providers' => [], // Would check actual SSO configuration
            'recent_logins' => 0, // Would count recent SSO logins
        ];
    }

    protected function getConnectorStats(string $tenantId): array
    {
        $connectors = DB::table('tenant_connector_configurations')
            ->where('tenant_id', $tenantId)
            ->get();

        $stats = [];
        foreach ($connectors as $connector) {
            $recentCalls = DB::table('api_call_metrics')
                ->where('tenant_id', $tenantId)
                ->where('connector', $connector->connector_name)
                ->where('created_at', '>=', Carbon::now()->subDays(7))
                ->count();

            $successRate = DB::table('api_call_metrics')
                ->where('tenant_id', $tenantId)
                ->where('connector', $connector->connector_name)
                ->where('created_at', '>=', Carbon::now()->subDays(7))
                ->selectRaw('AVG(CASE WHEN success = 1 THEN 1.0 ELSE 0.0 END) as success_rate')
                ->value('success_rate');

            $stats[] = [
                'name' => $connector->connector_name,
                'recent_calls' => $recentCalls,
                'success_rate' => round($successRate ?? 0, 2),
                'status' => $recentCalls > 0 ? 'active' : 'inactive',
            ];
        }

        return $stats;
    }

    protected function getWebhookStats(string $tenantId): array
    {
        $webhooks = DB::table('webhook_endpoints')
            ->where('tenant_id', $tenantId)
            ->get();

        $totalWebhooks = $webhooks->count();
        $activeWebhooks = $webhooks->where('active', true)->count();

        $recentDeliveries = DB::table('webhook_deliveries')
            ->join('webhook_endpoints', 'webhook_deliveries.webhook_id', '=', 'webhook_endpoints.id')
            ->where('webhook_endpoints.tenant_id', $tenantId)
            ->where('webhook_deliveries.created_at', '>=', Carbon::now()->subDays(7))
            ->count();

        $successRate = DB::table('webhook_deliveries')
            ->join('webhook_endpoints', 'webhook_deliveries.webhook_id', '=', 'webhook_endpoints.id')
            ->where('webhook_endpoints.tenant_id', $tenantId)
            ->where('webhook_deliveries.created_at', '>=', Carbon::now()->subDays(7))
            ->selectRaw('AVG(CASE WHEN success = 1 THEN 1.0 ELSE 0.0 END) as success_rate')
            ->value('success_rate');

        return [
            'total_webhooks' => $totalWebhooks,
            'active_webhooks' => $activeWebhooks,
            'recent_deliveries' => $recentDeliveries,
            'success_rate' => round($successRate ?? 0, 2),
        ];
    }

    protected function getPluginStats(string $tenantId): array
    {
        $plugins = DB::table('tenant_plugins')
            ->where('tenant_id', $tenantId)
            ->get();

        $totalPlugins = $plugins->count();
        $activePlugins = $plugins->where('status', 'active')->count();

        $recentExecutions = DB::table('plugin_execution_log')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->count();

        return [
            'total_plugins' => $totalPlugins,
            'active_plugins' => $activePlugins,
            'recent_executions' => $recentExecutions,
        ];
    }

    protected function getSyncJobStats(string $tenantId): array
    {
        $syncJobs = DB::table('sync_jobs')
            ->where('tenant_id', $tenantId)
            ->get();

        $totalJobs = $syncJobs->count();
        $activeJobs = $syncJobs->where('active', true)->count();
        $runningJobs = $syncJobs->where('status', 'running')->count();

        $recentExecutions = DB::table('sync_executions')
            ->join('sync_jobs', 'sync_executions.sync_id', '=', 'sync_jobs.sync_id')
            ->where('sync_jobs.tenant_id', $tenantId)
            ->where('sync_executions.created_at', '>=', Carbon::now()->subDays(7))
            ->count();

        return [
            'total_jobs' => $totalJobs,
            'active_jobs' => $activeJobs,
            'running_jobs' => $runningJobs,
            'recent_executions' => $recentExecutions,
        ];
    }

    protected function getHealthOverview(string $tenantId): array
    {
        // Aggregate health status from all integration components
        $healthChecks = DB::table('integration_health_checks')
            ->where('tenant_id', $tenantId)
            ->where('checked_at', '>=', Carbon::now()->subHour())
            ->get();

        $totalChecks = $healthChecks->count();
        $healthyChecks = $healthChecks->where('status', 'healthy')->count();
        $degradedChecks = $healthChecks->where('status', 'degraded')->count();
        $unhealthyChecks = $healthChecks->where('status', 'unhealthy')->count();

        $overallStatus = 'healthy';
        if ($unhealthyChecks > 0) {
            $overallStatus = 'unhealthy';
        } elseif ($degradedChecks > 0) {
            $overallStatus = 'degraded';
        }

        return [
            'overall_status' => $overallStatus,
            'total_checks' => $totalChecks,
            'healthy_count' => $healthyChecks,
            'degraded_count' => $degradedChecks,
            'unhealthy_count' => $unhealthyChecks,
        ];
    }

    protected function getRecentActivity(string $tenantId): array
    {
        // Get recent integration-related activities
        $activities = [];

        // Recent API calls
        $recentAPICalls = DB::table('api_call_metrics')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', Carbon::now()->subHours(24))
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get(['connector', 'method', 'endpoint', 'success', 'created_at']);

        foreach ($recentAPICalls as $call) {
            $activities[] = [
                'type' => 'api_call',
                'description' => "{$call->method} {$call->endpoint} via {$call->connector}",
                'status' => $call->success ? 'success' : 'failed',
                'timestamp' => $call->created_at,
            ];
        }

        // Recent webhook deliveries
        $recentWebhooks = DB::table('webhook_deliveries')
            ->join('webhook_endpoints', 'webhook_deliveries.webhook_id', '=', 'webhook_endpoints.id')
            ->where('webhook_endpoints.tenant_id', $tenantId)
            ->where('webhook_deliveries.created_at', '>=', Carbon::now()->subHours(24))
            ->orderBy('webhook_deliveries.created_at', 'desc')
            ->limit(10)
            ->get(['webhook_deliveries.delivery_id', 'webhook_deliveries.success', 'webhook_deliveries.created_at']);

        foreach ($recentWebhooks as $webhook) {
            $activities[] = [
                'type' => 'webhook_delivery',
                'description' => "Webhook delivery {$webhook->delivery_id}",
                'status' => $webhook->success ? 'success' : 'failed',
                'timestamp' => $webhook->created_at,
            ];
        }

        // Sort by timestamp and return latest 20
        usort($activities, function ($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        return array_slice($activities, 0, 20);
    }

    protected function getTimeRangeFromRequest(Request $request): array
    {
        $period = $request->get('period', '7d');
        
        switch ($period) {
            case '1d':
                return [Carbon::now()->subDay(), Carbon::now()];
            case '7d':
                return [Carbon::now()->subDays(7), Carbon::now()];
            case '30d':
                return [Carbon::now()->subDays(30), Carbon::now()];
            case '90d':
                return [Carbon::now()->subDays(90), Carbon::now()];
            default:
                return [Carbon::now()->subDays(7), Carbon::now()];
        }
    }

    protected function getUsageTrends(string $tenantId, array $timeRange): array
    {
        // Implementation would generate usage trend data
        return [
            'api_calls' => [],
            'webhook_deliveries' => [],
            'sync_executions' => [],
            'plugin_executions' => [],
        ];
    }

    protected function getPerformanceMetrics(string $tenantId, array $timeRange): array
    {
        // Implementation would generate performance metrics
        return [
            'avg_api_response_time' => 0,
            'webhook_delivery_rate' => 0,
            'sync_performance' => 0,
            'error_rates' => [],
        ];
    }

    protected function getErrorAnalysis(string $tenantId, array $timeRange): array
    {
        // Implementation would analyze errors across all integration types
        return [
            'total_errors' => 0,
            'error_by_type' => [],
            'error_trends' => [],
            'top_error_messages' => [],
        ];
    }

    protected function getCostBreakdown(string $tenantId, array $timeRange): array
    {
        // Implementation would calculate costs for different integration types
        return [
            'total_cost' => 0,
            'cost_by_service' => [],
            'cost_trends' => [],
        ];
    }

    protected function getIntegrationAdoption(string $tenantId): array
    {
        // Implementation would show adoption metrics
        return [
            'adoption_rate' => 0,
            'most_used_integrations' => [],
            'least_used_integrations' => [],
        ];
    }

    protected function getOverallHealthStatus(string $tenantId): string
    {
        // Implementation would determine overall health
        return 'healthy';
    }

    protected function getComponentHealth(string $tenantId, string $componentType): array
    {
        // Implementation would check specific component health
        return [
            'status' => 'healthy',
            'last_check' => Carbon::now()->toISOString(),
            'response_time' => 100,
            'issues' => [],
        ];
    }

    protected function getActiveAlerts(string $tenantId): array
    {
        // Implementation would get active alerts
        return [];
    }

    protected function getHealthRecommendations(string $tenantId): array
    {
        // Implementation would generate health recommendations
        return [];
    }
}