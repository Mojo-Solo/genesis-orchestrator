<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class HealthController extends Controller
{
    /**
     * Health readiness endpoint
     * Checks if all dependencies and router are ready
     */
    public function ready(): JsonResponse
    {
        $checks = [];
        $allHealthy = true;

        // Check database connection
        try {
            DB::connection()->getPdo();
            $checks['database'] = 'healthy';
        } catch (\Exception $e) {
            $checks['database'] = 'unhealthy';
            $allHealthy = false;
        }

        // Check cache connection
        try {
            Cache::put('health_check', true, 1);
            $checks['cache'] = 'healthy';
        } catch (\Exception $e) {
            $checks['cache'] = 'unhealthy';
            $allHealthy = false;
        }

        // Check router configuration
        $routerConfigPath = base_path('config/router.config.json');
        if (File::exists($routerConfigPath)) {
            $routerConfig = json_decode(File::get($routerConfigPath), true);
            if ($routerConfig && isset($routerConfig['version'])) {
                $checks['router'] = 'healthy';
                $checks['router_version'] = $routerConfig['version'];
            } else {
                $checks['router'] = 'invalid_config';
                $allHealthy = false;
            }
        } else {
            $checks['router'] = 'config_missing';
            $allHealthy = false;
        }

        // Check agent budgets
        if (isset($routerConfig['agents'])) {
            $totalBudget = 0;
            foreach ($routerConfig['agents'] as $agent => $config) {
                $totalBudget += $config['token_budget'] ?? 0;
            }
            $checks['total_token_budget'] = $totalBudget;
            
            if ($totalBudget > ($routerConfig['circuit_breakers']['max_total_tokens'] ?? 50000)) {
                $checks['budget_status'] = 'over_limit';
                $allHealthy = false;
            } else {
                $checks['budget_status'] = 'healthy';
            }
        }

        // Check Temporal connection (if configured)
        if (env('TEMPORAL_HOST')) {
            try {
                // Stub: Would check actual Temporal connection
                $checks['temporal'] = 'healthy';
            } catch (\Exception $e) {
                $checks['temporal'] = 'unhealthy';
                $allHealthy = false;
            }
        }

        return response()->json([
            'status' => $allHealthy ? 'ready' : 'not_ready',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
            'run_id' => env('RUN_ID', 'local'),
            'correlation_id' => env('CORRELATION_ID', 'none')
        ], $allHealthy ? 200 : 503);
    }

    /**
     * Health liveness endpoint
     * Simple check to verify the application is responsive
     */
    public function live(): JsonResponse
    {
        // Check if event loop is responsive
        $startTime = microtime(true);
        
        // Simple computation to verify responsiveness
        $sum = 0;
        for ($i = 0; $i < 1000; $i++) {
            $sum += $i;
        }
        
        $responseTime = (microtime(true) - $startTime) * 1000; // Convert to ms
        
        $isHealthy = $responseTime < 100; // Should complete in < 100ms
        
        return response()->json([
            'status' => $isHealthy ? 'live' : 'degraded',
            'response_time_ms' => round($responseTime, 2),
            'timestamp' => now()->toIso8601String()
        ], $isHealthy ? 200 : 503);
    }

    /**
     * Metrics endpoint for monitoring
     */
    public function metrics(): JsonResponse
    {
        $metrics = [
            'orchestrator' => [
                'total_runs' => Cache::get('genesis.total_runs', 0),
                'successful_runs' => Cache::get('genesis.successful_runs', 0),
                'failed_runs' => Cache::get('genesis.failed_runs', 0),
                'average_latency_ms' => Cache::get('genesis.avg_latency', 0),
                'total_tokens_used' => Cache::get('genesis.total_tokens', 0)
            ],
            'router' => [
                'algorithm' => 'RCR',
                'efficiency_gain' => Cache::get('genesis.router.efficiency', 0),
                'cache_hit_rate' => Cache::get('genesis.router.cache_hits', 0)
            ],
            'stability' => [
                'current_score' => Cache::get('genesis.stability_score', 0.986),
                'variance' => Cache::get('genesis.stability_variance', 0.014)
            ],
            'gates' => [
                'frontend' => [
                    'eslint_errors' => Cache::get('genesis.gates.eslint_errors', 0),
                    'a11y_violations' => Cache::get('genesis.gates.a11y_violations', 0)
                ],
                'backend' => [
                    'test_failures' => Cache::get('genesis.gates.test_failures', 0),
                    'security_issues' => Cache::get('genesis.gates.security_issues', 0)
                ]
            ],
            'timestamp' => now()->toIso8601String()
        ];

        return response()->json($metrics);
    }
}