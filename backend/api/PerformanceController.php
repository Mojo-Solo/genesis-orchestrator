<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RouterMetric;
use App\Models\OrchestrationRun;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * GENESIS Orchestrator - Performance Profiling API Controller
 * External integrations for performance monitoring and analytics
 */
class PerformanceController extends Controller
{
    /**
     * Get current performance metrics
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getCurrentMetrics(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'include_system' => 'boolean',
                'include_traces' => 'boolean',
                'include_bottlenecks' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed',
                    'details' => $validator->errors()
                ], 400);
            }

            $metrics = [
                'timestamp' => time(),
                'orchestration' => $this->getOrchestrationMetrics(),
                'routing' => $this->getRoutingMetrics(),
            ];

            // Include optional metrics based on request parameters
            if ($request->get('include_system', false)) {
                $metrics['system'] = $this->getSystemMetrics();
            }

            if ($request->get('include_traces', false)) {
                $metrics['traces'] = $this->getTracingMetrics();
            }

            if ($request->get('include_bottlenecks', false)) {
                $metrics['bottlenecks'] = $this->getBottleneckMetrics();
            }

            return response()->json([
                'success' => true,
                'data' => $metrics,
                'generated_at' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get current metrics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to retrieve current metrics',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get performance metrics history
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getMetricsHistory(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'hours' => 'integer|min:1|max:168', // Max 7 days
                'limit' => 'integer|min:1|max:1000',
                'metric_types' => 'array',
                'metric_types.*' => 'string|in:orchestration,routing,system,performance'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed',
                    'details' => $validator->errors()
                ], 400);
            }

            $hours = $request->get('hours', 24);
            $limit = $request->get('limit', 100);
            $metricTypes = $request->get('metric_types', ['orchestration', 'routing']);

            $cutoffTime = Carbon::now()->subHours($hours);
            
            $history = [];

            if (in_array('orchestration', $metricTypes)) {
                $orchestrationHistory = OrchestrationRun::where('created_at', '>=', $cutoffTime)
                    ->orderBy('created_at', 'desc')
                    ->limit($limit)
                    ->get();

                $history['orchestration'] = $orchestrationHistory->map(function ($run) {
                    return [
                        'run_id' => $run->run_id,
                        'correlation_id' => $run->correlation_id,
                        'timestamp' => $run->created_at->timestamp,
                        'success' => $run->success,
                        'duration_seconds' => $run->duration_seconds,
                        'total_tokens' => $run->total_tokens,
                        'cost_usd' => $run->cost_usd,
                        'agent_count' => $run->agent_count
                    ];
                });
            }

            if (in_array('routing', $metricTypes)) {
                $routingHistory = RouterMetric::where('created_at', '>=', $cutoffTime)
                    ->orderBy('created_at', 'desc')
                    ->limit($limit)
                    ->get();

                $history['routing'] = $routingHistory->map(function ($metric) {
                    return [
                        'run_id' => $metric->run_id,
                        'timestamp' => $metric->created_at->timestamp,
                        'algorithm' => $metric->algorithm,
                        'token_savings_percentage' => $metric->token_savings_percentage,
                        'selection_time_ms' => $metric->selection_time_ms,
                        'efficiency_gain' => $metric->efficiency_gain,
                        'total_selected_tokens' => $metric->total_selected_tokens
                    ];
                });
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'time_range' => [
                        'from' => $cutoffTime->toISOString(),
                        'to' => now()->toISOString(),
                        'hours' => $hours
                    ],
                    'metrics' => $history,
                    'total_records' => collect($history)->sum(fn($type) => count($type))
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get metrics history', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to retrieve metrics history',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get performance analytics and insights
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getPerformanceAnalytics(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'period' => 'string|in:1h,6h,24h,7d,30d',
                'include_trends' => 'boolean',
                'include_predictions' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed',
                    'details' => $validator->errors()
                ], 400);
            }

            $period = $request->get('period', '24h');
            $includeTrends = $request->get('include_trends', true);
            $includePredictions = $request->get('include_predictions', false);

            // Parse period
            $hours = $this->parsePeriodToHours($period);
            $cutoffTime = Carbon::now()->subHours($hours);

            $analytics = [
                'period' => $period,
                'generated_at' => now()->toISOString(),
                'orchestration_analytics' => $this->getOrchestrationAnalytics($cutoffTime),
                'routing_analytics' => $this->getRoutingAnalytics($cutoffTime),
                'performance_summary' => $this->getPerformanceSummary($cutoffTime)
            ];

            if ($includeTrends) {
                $analytics['trends'] = $this->calculatePerformanceTrends($cutoffTime);
            }

            if ($includePredictions) {
                $analytics['predictions'] = $this->generatePerformancePredictions($cutoffTime);
            }

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get performance analytics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to retrieve performance analytics',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Record external performance metric
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function recordMetric(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'metric_name' => 'required|string|max:100',
                'metric_value' => 'required|numeric',
                'metric_type' => 'required|string|in:gauge,counter,histogram,summary',
                'labels' => 'array',
                'labels.*' => 'string|max:255',
                'timestamp' => 'integer|min:1',
                'run_id' => 'string|max:100',
                'correlation_id' => 'string|max:100'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed',
                    'details' => $validator->errors()
                ], 400);
            }

            $metricData = [
                'metric_name' => $request->input('metric_name'),
                'metric_value' => $request->input('metric_value'),
                'metric_type' => $request->input('metric_type'),
                'labels' => $request->input('labels', []),
                'timestamp' => $request->input('timestamp', time()),
                'run_id' => $request->input('run_id'),
                'correlation_id' => $request->input('correlation_id'),
                'source' => 'external_api',
                'recorded_at' => now()
            ];

            // Store metric (this would typically go to a time-series database)
            // For now, we'll log it and could extend to store in a metrics table
            Log::info('External metric recorded', $metricData);

            // Call Python monitoring service if available
            $this->forwardToPythonService($metricData);

            return response()->json([
                'success' => true,
                'message' => 'Metric recorded successfully',
                'metric_id' => uniqid('metric_', true)
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to record external metric', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'error' => 'Failed to record metric',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get performance benchmarks and baselines
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getBenchmarks(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'benchmark_type' => 'string|in:orchestration,routing,system,all',
                'include_history' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed',
                    'details' => $validator->errors()
                ], 400);
            }

            $benchmarkType = $request->get('benchmark_type', 'all');
            $includeHistory = $request->get('include_history', false);

            $benchmarks = [];

            if ($benchmarkType === 'all' || $benchmarkType === 'orchestration') {
                $benchmarks['orchestration'] = $this->getOrchestrationBenchmarks($includeHistory);
            }

            if ($benchmarkType === 'all' || $benchmarkType === 'routing') {
                $benchmarks['routing'] = $this->getRoutingBenchmarks($includeHistory);
            }

            if ($benchmarkType === 'all' || $benchmarkType === 'system') {
                $benchmarks['system'] = $this->getSystemBenchmarks($includeHistory);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'benchmarks' => $benchmarks,
                    'generated_at' => now()->toISOString(),
                    'benchmark_type' => $benchmarkType
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get performance benchmarks', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to retrieve benchmarks',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export performance data
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function exportPerformanceData(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'format' => 'required|string|in:json,csv,prometheus',
                'data_types' => 'required|array',
                'data_types.*' => 'string|in:orchestration,routing,metrics,traces',
                'start_time' => 'required|integer',
                'end_time' => 'required|integer',
                'include_metadata' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed',
                    'details' => $validator->errors()
                ], 400);
            }

            $format = $request->input('format');
            $dataTypes = $request->input('data_types');
            $startTime = $request->input('start_time');
            $endTime = $request->input('end_time');
            $includeMetadata = $request->get('include_metadata', true);

            // Validate time range
            if ($endTime <= $startTime) {
                return response()->json([
                    'error' => 'Invalid time range',
                    'message' => 'End time must be after start time'
                ], 400);
            }

            $startDate = Carbon::createFromTimestamp($startTime);
            $endDate = Carbon::createFromTimestamp($endTime);

            // Check if time range is too large (max 30 days)
            if ($startDate->diffInDays($endDate) > 30) {
                return response()->json([
                    'error' => 'Time range too large',
                    'message' => 'Maximum export range is 30 days'
                ], 400);
            }

            $exportData = $this->collectExportData($dataTypes, $startDate, $endDate, $includeMetadata);

            switch ($format) {
                case 'json':
                    $result = $this->formatAsJson($exportData);
                    break;
                case 'csv':
                    $result = $this->formatAsCsv($exportData);
                    break;
                case 'prometheus':
                    $result = $this->formatAsPrometheus($exportData);
                    break;
                default:
                    return response()->json(['error' => 'Unsupported format'], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $result,
                'metadata' => [
                    'format' => $format,
                    'data_types' => $dataTypes,
                    'time_range' => [
                        'start' => $startDate->toISOString(),
                        'end' => $endDate->toISOString()
                    ],
                    'record_count' => $this->countExportRecords($exportData),
                    'generated_at' => now()->toISOString()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to export performance data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to export data',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get orchestration performance metrics
     */
    private function getOrchestrationMetrics(): array
    {
        // Get recent orchestration runs
        $recentRuns = OrchestrationRun::where('created_at', '>=', Carbon::now()->subHour())
            ->get();

        $totalRuns = OrchestrationRun::count();
        $successfulRuns = OrchestrationRun::where('success', true)->count();

        return [
            'total_runs' => $totalRuns,
            'successful_runs' => $successfulRuns,
            'failed_runs' => $totalRuns - $successfulRuns,
            'success_rate' => $totalRuns > 0 ? ($successfulRuns / $totalRuns) * 100 : 100,
            'avg_duration_seconds' => OrchestrationRun::avg('duration_seconds') ?: 0,
            'total_tokens' => OrchestrationRun::sum('total_tokens') ?: 0,
            'total_cost_usd' => OrchestrationRun::sum('cost_usd') ?: 0,
            'recent_activity' => [
                'last_hour_runs' => $recentRuns->count(),
                'last_hour_success_rate' => $recentRuns->count() > 0 ? 
                    ($recentRuns->where('success', true)->count() / $recentRuns->count()) * 100 : 100
            ]
        ];
    }

    /**
     * Get routing performance metrics
     */
    private function getRoutingMetrics(): array
    {
        $metrics = RouterMetric::getMetricsByAlgorithm('RCR');
        
        return [
            'algorithm' => 'RCR',
            'avg_token_savings' => $metrics['avg_savings'] ?: 0,
            'avg_selection_time_ms' => $metrics['avg_time'] ?: 0,
            'avg_efficiency_gain' => $metrics['avg_efficiency'] ?: 0,
            'total_routing_decisions' => $metrics['total_runs'] ?: 0,
            'performance_score' => $this->calculateRoutingPerformanceScore($metrics)
        ];
    }

    /**
     * Get system performance metrics (placeholder)
     */
    private function getSystemMetrics(): array
    {
        // This would typically interface with system monitoring
        // For now, return simulated metrics
        return [
            'cpu_usage_percent' => rand(10, 80),
            'memory_usage_percent' => rand(20, 75),
            'disk_usage_percent' => rand(15, 60),
            'load_average' => rand(1, 4),
            'active_connections' => rand(5, 50),
            'uptime_hours' => rand(1, 168) // Max 1 week
        ];
    }

    /**
     * Get distributed tracing metrics (placeholder)
     */
    private function getTracingMetrics(): array
    {
        // This would interface with the Python tracing service
        return [
            'active_traces' => rand(0, 10),
            'avg_trace_duration_ms' => rand(50, 2000),
            'trace_error_rate' => rand(0, 5) / 100,
            'services_traced' => rand(3, 8)
        ];
    }

    /**
     * Get bottleneck metrics (placeholder)
     */
    private function getBottleneckMetrics(): array
    {
        // This would interface with the Python bottleneck detection service
        return [
            'active_bottlenecks' => rand(0, 3),
            'resolved_bottlenecks_today' => rand(0, 5),
            'most_common_type' => ['cpu_bound', 'memory_bound', 'io_bound'][rand(0, 2)],
            'avg_impact_score' => rand(10, 80)
        ];
    }

    /**
     * Calculate routing performance score
     */
    private function calculateRoutingPerformanceScore(array $metrics): float
    {
        $savings = $metrics['avg_savings'] ?: 0;
        $speed = 1000 / max($metrics['avg_time'] ?: 1, 1); // Inverse of time
        $efficiency = $metrics['avg_efficiency'] ?: 0;
        
        // Weighted score (0-100)
        return min(100, ($savings * 0.5 + $speed * 0.3 + $efficiency * 100 * 0.2));
    }

    /**
     * Parse period string to hours
     */
    private function parsePeriodToHours(string $period): int
    {
        $periodMap = [
            '1h' => 1,
            '6h' => 6,
            '24h' => 24,
            '7d' => 168,
            '30d' => 720
        ];

        return $periodMap[$period] ?? 24;
    }

    /**
     * Get orchestration analytics
     */
    private function getOrchestrationAnalytics(Carbon $cutoffTime): array
    {
        $runs = OrchestrationRun::where('created_at', '>=', $cutoffTime)->get();
        
        if ($runs->isEmpty()) {
            return ['message' => 'No data available for the selected period'];
        }

        return [
            'total_runs' => $runs->count(),
            'success_rate' => ($runs->where('success', true)->count() / $runs->count()) * 100,
            'avg_duration' => $runs->avg('duration_seconds'),
            'avg_tokens' => $runs->avg('total_tokens'),
            'avg_cost' => $runs->avg('cost_usd'),
            'performance_distribution' => [
                'fast_runs' => $runs->where('duration_seconds', '<', 10)->count(),
                'medium_runs' => $runs->whereBetween('duration_seconds', [10, 30])->count(),
                'slow_runs' => $runs->where('duration_seconds', '>', 30)->count()
            ]
        ];
    }

    /**
     * Get routing analytics
     */
    private function getRoutingAnalytics(Carbon $cutoffTime): array
    {
        $metrics = RouterMetric::where('created_at', '>=', $cutoffTime)->get();
        
        if ($metrics->isEmpty()) {
            return ['message' => 'No routing data available for the selected period'];
        }

        return [
            'total_decisions' => $metrics->count(),
            'avg_token_savings' => $metrics->avg('token_savings_percentage'),
            'avg_selection_time' => $metrics->avg('selection_time_ms'),
            'efficiency_distribution' => [
                'high_efficiency' => $metrics->where('efficiency_gain', '>', 0.3)->count(),
                'medium_efficiency' => $metrics->whereBetween('efficiency_gain', [0.1, 0.3])->count(),
                'low_efficiency' => $metrics->where('efficiency_gain', '<', 0.1)->count()
            ]
        ];
    }

    /**
     * Get performance summary
     */
    private function getPerformanceSummary(Carbon $cutoffTime): array
    {
        $orchestrationMetrics = $this->getOrchestrationAnalytics($cutoffTime);
        $routingMetrics = $this->getRoutingAnalytics($cutoffTime);

        return [
            'overall_health' => $this->calculateOverallHealthScore($orchestrationMetrics, $routingMetrics),
            'key_insights' => $this->generateKeyInsights($orchestrationMetrics, $routingMetrics),
            'recommendations' => $this->generateRecommendations($orchestrationMetrics, $routingMetrics)
        ];
    }

    /**
     * Calculate performance trends
     */
    private function calculatePerformanceTrends(Carbon $cutoffTime): array
    {
        // Simplified trend calculation
        $currentMetrics = $this->getOrchestrationAnalytics($cutoffTime);
        $previousMetrics = $this->getOrchestrationAnalytics($cutoffTime->copy()->subHours(24));

        return [
            'success_rate_trend' => $this->calculateTrendDirection(
                $previousMetrics['success_rate'] ?? 100,
                $currentMetrics['success_rate'] ?? 100
            ),
            'duration_trend' => $this->calculateTrendDirection(
                $previousMetrics['avg_duration'] ?? 0,
                $currentMetrics['avg_duration'] ?? 0,
                true // Lower is better
            )
        ];
    }

    /**
     * Generate performance predictions (placeholder)
     */
    private function generatePerformancePredictions(Carbon $cutoffTime): array
    {
        // This would use ML models in production
        return [
            'predicted_load' => 'moderate',
            'expected_bottlenecks' => ['memory_usage'],
            'recommended_actions' => ['Monitor memory usage', 'Consider scaling']
        ];
    }

    /**
     * Calculate overall health score
     */
    private function calculateOverallHealthScore(array $orchestration, array $routing): string
    {
        $score = 100;
        
        if (isset($orchestration['success_rate']) && $orchestration['success_rate'] < 95) {
            $score -= 20;
        }
        
        if (isset($orchestration['avg_duration']) && $orchestration['avg_duration'] > 30) {
            $score -= 15;
        }

        if ($score >= 85) return 'excellent';
        if ($score >= 70) return 'good';
        if ($score >= 50) return 'fair';
        return 'poor';
    }

    /**
     * Generate key insights
     */
    private function generateKeyInsights(array $orchestration, array $routing): array
    {
        $insights = [];

        if (isset($orchestration['success_rate']) && $orchestration['success_rate'] < 90) {
            $insights[] = 'Success rate below optimal threshold';
        }

        if (isset($routing['avg_token_savings']) && $routing['avg_token_savings'] > 30) {
            $insights[] = 'RCR routing showing excellent token savings';
        }

        return $insights;
    }

    /**
     * Generate recommendations
     */
    private function generateRecommendations(array $orchestration, array $routing): array
    {
        $recommendations = [];

        if (isset($orchestration['avg_duration']) && $orchestration['avg_duration'] > 20) {
            $recommendations[] = 'Consider optimizing orchestration workflows';
        }

        return $recommendations;
    }

    /**
     * Calculate trend direction
     */
    private function calculateTrendDirection(float $previous, float $current, bool $lowerIsBetter = false): string
    {
        $change = $current - $previous;
        $threshold = abs($previous) * 0.05; // 5% threshold

        if (abs($change) < $threshold) {
            return 'stable';
        }

        if ($lowerIsBetter) {
            return $change < 0 ? 'improving' : 'declining';
        } else {
            return $change > 0 ? 'improving' : 'declining';
        }
    }

    /**
     * Forward metric to Python service
     */
    private function forwardToPythonService(array $metricData): void
    {
        try {
            // This would make an HTTP call to the Python monitoring service
            Log::debug('Forwarding metric to Python service', $metricData);
        } catch (\Exception $e) {
            Log::warning('Failed to forward metric to Python service', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get orchestration benchmarks
     */
    private function getOrchestrationBenchmarks(bool $includeHistory): array
    {
        $benchmarks = [
            'avg_duration_baseline' => 15.0, // seconds
            'success_rate_target' => 95.0,   // percentage
            'cost_efficiency_target' => 0.10 // USD per successful run
        ];

        if ($includeHistory) {
            $benchmarks['historical_performance'] = [
                'last_30_days' => [
                    'avg_duration' => OrchestrationRun::where('created_at', '>=', Carbon::now()->subDays(30))->avg('duration_seconds'),
                    'success_rate' => $this->calculateSuccessRate(30),
                    'avg_cost' => OrchestrationRun::where('created_at', '>=', Carbon::now()->subDays(30))->avg('cost_usd')
                ]
            ];
        }

        return $benchmarks;
    }

    /**
     * Get routing benchmarks
     */
    private function getRoutingBenchmarks(bool $includeHistory): array
    {
        return [
            'token_savings_target' => 25.0,    // percentage
            'selection_time_target' => 100.0,  // milliseconds
            'efficiency_gain_target' => 0.20   // 20% efficiency gain
        ];
    }

    /**
     * Get system benchmarks
     */
    private function getSystemBenchmarks(bool $includeHistory): array
    {
        return [
            'cpu_usage_threshold' => 80.0,
            'memory_usage_threshold' => 85.0,
            'response_time_target' => 200.0, // milliseconds
            'uptime_target' => 99.9 // percentage
        ];
    }

    /**
     * Calculate success rate for a given period
     */
    private function calculateSuccessRate(int $days): float
    {
        $total = OrchestrationRun::where('created_at', '>=', Carbon::now()->subDays($days))->count();
        $successful = OrchestrationRun::where('created_at', '>=', Carbon::now()->subDays($days))
            ->where('success', true)->count();

        return $total > 0 ? ($successful / $total) * 100 : 100.0;
    }

    /**
     * Collect export data
     */
    private function collectExportData(array $dataTypes, Carbon $startDate, Carbon $endDate, bool $includeMetadata): array
    {
        $data = [];

        if (in_array('orchestration', $dataTypes)) {
            $data['orchestration'] = OrchestrationRun::whereBetween('created_at', [$startDate, $endDate])
                ->get()
                ->toArray();
        }

        if (in_array('routing', $dataTypes)) {
            $data['routing'] = RouterMetric::whereBetween('created_at', [$startDate, $endDate])
                ->get()
                ->toArray();
        }

        if ($includeMetadata) {
            $data['metadata'] = [
                'export_generated_at' => now()->toISOString(),
                'time_range' => [
                    'start' => $startDate->toISOString(),
                    'end' => $endDate->toISOString()
                ],
                'data_types' => $dataTypes
            ];
        }

        return $data;
    }

    /**
     * Format data as JSON
     */
    private function formatAsJson(array $data): array
    {
        return $data;
    }

    /**
     * Format data as CSV
     */
    private function formatAsCsv(array $data): string
    {
        // Simplified CSV formatting
        $csv = '';
        
        foreach ($data as $type => $records) {
            if ($type === 'metadata') continue;
            
            $csv .= "# $type\n";
            
            if (!empty($records)) {
                $headers = array_keys($records[0]);
                $csv .= implode(',', $headers) . "\n";
                
                foreach ($records as $record) {
                    $csv .= implode(',', array_values($record)) . "\n";
                }
            }
            
            $csv .= "\n";
        }

        return $csv;
    }

    /**
     * Format data as Prometheus metrics
     */
    private function formatAsPrometheus(array $data): string
    {
        $prometheus = '';
        
        if (isset($data['orchestration'])) {
            $prometheus .= "# HELP genesis_orchestration_runs_total Total orchestration runs\n";
            $prometheus .= "# TYPE genesis_orchestration_runs_total counter\n";
            $prometheus .= "genesis_orchestration_runs_total " . count($data['orchestration']) . "\n\n";
        }

        return $prometheus;
    }

    /**
     * Count total export records
     */
    private function countExportRecords(array $data): int
    {
        $count = 0;
        foreach ($data as $key => $records) {
            if ($key !== 'metadata' && is_array($records)) {
                $count += count($records);
            }
        }
        return $count;
    }
}