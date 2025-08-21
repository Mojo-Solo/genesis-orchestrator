<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\MonitoringMetric;

/**
 * API Performance Metrics Collector
 * 
 * Collects and analyzes API performance metrics including
 * response times, throughput, error rates, and endpoint analysis
 */
class ApiPerformanceCollector implements MetricCollectorInterface
{
    /**
     * Collect API performance metrics
     */
    public function collect(array $options = []): array
    {
        $timeWindow = $options['time_window'] ?? '5m';
        $startTime = $this->getStartTime($timeWindow);

        return [
            'response_time' => $this->getResponseTimeMetrics($startTime),
            'throughput' => $this->getThroughputMetrics($startTime),
            'error_rate' => $this->getErrorRateMetrics($startTime),
            'endpoint_performance' => $this->getEndpointPerformance($startTime),
            'status_code_distribution' => $this->getStatusCodeDistribution($startTime),
            'tenant_performance' => $this->getTenantPerformanceBreakdown($startTime),
            'peak_performance' => $this->getPeakPerformanceMetrics($startTime),
            'sla_compliance' => $this->getSlaComplianceMetrics($startTime),
        ];
    }

    /**
     * Get response time metrics
     */
    protected function getResponseTimeMetrics(\DateTime $startTime): array
    {
        $cacheKey = "api_response_time_metrics_" . $startTime->getTimestamp();
        
        return Cache::remember($cacheKey, 60, function () use ($startTime) {
            $metrics = MonitoringMetric::where('series', 'api_requests')
                ->where('timestamp', '>=', $startTime)
                ->whereNotNull('data->response_time_ms')
                ->get();

            if ($metrics->isEmpty()) {
                return $this->getEmptyResponseTimeMetrics();
            }

            $responseTimes = $metrics->pluck('data.response_time_ms')->filter();

            return [
                'avg' => round($responseTimes->avg(), 2),
                'min' => $responseTimes->min(),
                'max' => $responseTimes->max(),
                'median' => $this->calculatePercentile($responseTimes->toArray(), 50),
                'p95' => $this->calculatePercentile($responseTimes->toArray(), 95),
                'p99' => $this->calculatePercentile($responseTimes->toArray(), 99),
                'std_dev' => round($this->calculateStandardDeviation($responseTimes->toArray()), 2),
                'sample_count' => $responseTimes->count(),
                'target_met' => $responseTimes->filter(fn($time) => $time <= 100)->count() / $responseTimes->count() * 100,
            ];
        });
    }

    /**
     * Get throughput metrics (requests per second)
     */
    protected function getThroughputMetrics(\DateTime $startTime): array
    {
        $cacheKey = "api_throughput_metrics_" . $startTime->getTimestamp();
        
        return Cache::remember($cacheKey, 60, function () use ($startTime) {
            $totalRequests = MonitoringMetric::where('series', 'api_requests')
                ->where('timestamp', '>=', $startTime)
                ->count();

            $durationMinutes = now()->diffInMinutes($startTime);
            $durationSeconds = max(1, $durationMinutes * 60);

            $requestsPerSecond = $totalRequests / $durationSeconds;
            $requestsPerMinute = $totalRequests / max(1, $durationMinutes);

            // Get peak throughput in 1-minute windows
            $peakThroughput = $this->calculatePeakThroughput($startTime);

            return [
                'requests_per_second' => round($requestsPerSecond, 2),
                'requests_per_minute' => round($requestsPerMinute, 2),
                'total_requests' => $totalRequests,
                'peak_rps' => $peakThroughput['peak_rps'],
                'peak_time' => $peakThroughput['peak_time'],
                'target_met' => $requestsPerSecond >= 1000, // Phase 7 target
                'capacity_utilization' => min(100, ($requestsPerSecond / 2500) * 100), // vs 2500 RPS target
            ];
        });
    }

    /**
     * Get error rate metrics
     */
    protected function getErrorRateMetrics(\DateTime $startTime): array
    {
        $cacheKey = "api_error_rate_metrics_" . $startTime->getTimestamp();
        
        return Cache::remember($cacheKey, 60, function () use ($startTime) {
            $allRequests = MonitoringMetric::where('series', 'api_requests')
                ->where('timestamp', '>=', $startTime)
                ->get();

            if ($allRequests->isEmpty()) {
                return $this->getEmptyErrorRateMetrics();
            }

            $totalRequests = $allRequests->count();
            $errorRequests = $allRequests->filter(function ($metric) {
                $statusCode = $metric->data['status_code'] ?? 200;
                return $statusCode >= 400;
            });

            $serverErrors = $allRequests->filter(function ($metric) {
                $statusCode = $metric->data['status_code'] ?? 200;
                return $statusCode >= 500;
            });

            $clientErrors = $allRequests->filter(function ($metric) {
                $statusCode = $metric->data['status_code'] ?? 200;
                return $statusCode >= 400 && $statusCode < 500;
            });

            $errorRate = ($errorRequests->count() / $totalRequests) * 100;
            $serverErrorRate = ($serverErrors->count() / $totalRequests) * 100;
            $clientErrorRate = ($clientErrors->count() / $totalRequests) * 100;

            return [
                'total_error_rate' => round($errorRate, 3),
                'server_error_rate' => round($serverErrorRate, 3),
                'client_error_rate' => round($clientErrorRate, 3),
                'error_count' => $errorRequests->count(),
                'server_error_count' => $serverErrors->count(),
                'client_error_count' => $clientErrors->count(),
                'success_rate' => round(100 - $errorRate, 3),
                'target_met' => $errorRate <= 0.5, // 99.5% success rate target
                'most_common_errors' => $this->getMostCommonErrors($errorRequests),
            ];
        });
    }

    /**
     * Get performance metrics by endpoint
     */
    protected function getEndpointPerformance(\DateTime $startTime): array
    {
        $cacheKey = "api_endpoint_performance_" . $startTime->getTimestamp();
        
        return Cache::remember($cacheKey, 60, function () use ($startTime) {
            $metrics = MonitoringMetric::where('series', 'api_requests')
                ->where('timestamp', '>=', $startTime)
                ->get();

            $endpointStats = [];

            foreach ($metrics as $metric) {
                $endpoint = $metric->data['endpoint'] ?? 'unknown';
                $method = $metric->data['method'] ?? 'GET';
                $key = "{$method} {$endpoint}";

                if (!isset($endpointStats[$key])) {
                    $endpointStats[$key] = [
                        'endpoint' => $endpoint,
                        'method' => $method,
                        'response_times' => [],
                        'status_codes' => [],
                        'request_count' => 0,
                    ];
                }

                $endpointStats[$key]['response_times'][] = $metric->data['response_time_ms'] ?? 0;
                $endpointStats[$key]['status_codes'][] = $metric->data['status_code'] ?? 200;
                $endpointStats[$key]['request_count']++;
            }

            // Calculate statistics for each endpoint
            return array_map(function ($stats) {
                $responseTimes = $stats['response_times'];
                $statusCodes = $stats['status_codes'];
                $errorCount = count(array_filter($statusCodes, fn($code) => $code >= 400));

                return [
                    'endpoint' => $stats['endpoint'],
                    'method' => $stats['method'],
                    'request_count' => $stats['request_count'],
                    'avg_response_time' => round(array_sum($responseTimes) / count($responseTimes), 2),
                    'p95_response_time' => $this->calculatePercentile($responseTimes, 95),
                    'error_rate' => round(($errorCount / count($statusCodes)) * 100, 3),
                    'requests_per_minute' => round($stats['request_count'] / max(1, now()->diffInMinutes($startTime)), 2),
                ];
            }, $endpointStats);
        });
    }

    /**
     * Get status code distribution
     */
    protected function getStatusCodeDistribution(\DateTime $startTime): array
    {
        $metrics = MonitoringMetric::where('series', 'api_requests')
            ->where('timestamp', '>=', $startTime)
            ->get();

        $distribution = [];
        $total = $metrics->count();

        if ($total === 0) {
            return $distribution;
        }

        foreach ($metrics as $metric) {
            $statusCode = $metric->data['status_code'] ?? 200;
            $statusClass = $this->getStatusCodeClass($statusCode);
            
            $distribution[$statusClass] = ($distribution[$statusClass] ?? 0) + 1;
        }

        // Convert to percentages
        return array_map(function ($count) use ($total) {
            return round(($count / $total) * 100, 2);
        }, $distribution);
    }

    /**
     * Get tenant performance breakdown
     */
    protected function getTenantPerformanceBreakdown(\DateTime $startTime): array
    {
        $metrics = MonitoringMetric::where('series', 'api_requests')
            ->where('timestamp', '>=', $startTime)
            ->whereNotNull('data->tenant_id')
            ->get();

        $tenantStats = [];

        foreach ($metrics as $metric) {
            $tenantId = $metric->data['tenant_id'];
            
            if (!isset($tenantStats[$tenantId])) {
                $tenantStats[$tenantId] = [
                    'tenant_id' => $tenantId,
                    'response_times' => [],
                    'error_count' => 0,
                    'request_count' => 0,
                ];
            }

            $tenantStats[$tenantId]['response_times'][] = $metric->data['response_time_ms'] ?? 0;
            $tenantStats[$tenantId]['request_count']++;
            
            if (($metric->data['status_code'] ?? 200) >= 400) {
                $tenantStats[$tenantId]['error_count']++;
            }
        }

        return array_map(function ($stats) {
            $responseTimes = $stats['response_times'];
            
            return [
                'tenant_id' => $stats['tenant_id'],
                'request_count' => $stats['request_count'],
                'avg_response_time' => round(array_sum($responseTimes) / count($responseTimes), 2),
                'error_rate' => round(($stats['error_count'] / $stats['request_count']) * 100, 3),
                'performance_score' => $this->calculatePerformanceScore($stats),
            ];
        }, $tenantStats);
    }

    /**
     * Get peak performance metrics
     */
    protected function getPeakPerformanceMetrics(\DateTime $startTime): array
    {
        $peakThroughput = $this->calculatePeakThroughput($startTime);
        $peakResponseTime = $this->calculatePeakResponseTime($startTime);

        return [
            'peak_throughput' => $peakThroughput,
            'peak_response_time' => $peakResponseTime,
            'performance_variance' => $this->calculatePerformanceVariance($startTime),
        ];
    }

    /**
     * Get SLA compliance metrics
     */
    protected function getSlaComplianceMetrics(\DateTime $startTime): array
    {
        $metrics = MonitoringMetric::where('series', 'api_requests')
            ->where('timestamp', '>=', $startTime)
            ->get();

        if ($metrics->isEmpty()) {
            return $this->getEmptySlaMetrics();
        }

        $totalRequests = $metrics->count();
        
        // SLA targets: <100ms response time, 99.5% success rate
        $fastRequests = $metrics->filter(fn($m) => ($m->data['response_time_ms'] ?? 0) <= 100)->count();
        $successfulRequests = $metrics->filter(fn($m) => ($m->data['status_code'] ?? 200) < 400)->count();

        return [
            'response_time_sla' => [
                'target' => '100ms',
                'compliance_rate' => round(($fastRequests / $totalRequests) * 100, 2),
                'met' => ($fastRequests / $totalRequests) >= 0.95,
            ],
            'availability_sla' => [
                'target' => '99.5%',
                'compliance_rate' => round(($successfulRequests / $totalRequests) * 100, 2),
                'met' => ($successfulRequests / $totalRequests) >= 0.995,
            ],
            'overall_sla_compliance' => [
                'met' => ($fastRequests / $totalRequests) >= 0.95 && ($successfulRequests / $totalRequests) >= 0.995,
                'score' => round((($fastRequests + $successfulRequests) / ($totalRequests * 2)) * 100, 2),
            ],
        ];
    }

    // Helper methods

    protected function getStartTime(string $timeWindow): \DateTime
    {
        $intervals = [
            '1m' => 1,
            '5m' => 5,
            '15m' => 15,
            '30m' => 30,
            '1h' => 60,
            '3h' => 180,
            '6h' => 360,
            '12h' => 720,
            '24h' => 1440,
        ];

        $minutes = $intervals[$timeWindow] ?? 5;
        return now()->subMinutes($minutes);
    }

    protected function calculatePercentile(array $values, int $percentile): float
    {
        if (empty($values)) {
            return 0.0;
        }

        sort($values);
        $index = ($percentile / 100) * (count($values) - 1);
        
        if (floor($index) == $index) {
            return $values[$index];
        }
        
        $lower = $values[floor($index)];
        $upper = $values[ceil($index)];
        $fraction = $index - floor($index);
        
        return $lower + ($fraction * ($upper - $lower));
    }

    protected function calculateStandardDeviation(array $values): float
    {
        if (count($values) <= 1) {
            return 0.0;
        }

        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $values)) / count($values);
        
        return sqrt($variance);
    }

    protected function calculatePeakThroughput(\DateTime $startTime): array
    {
        $metrics = MonitoringMetric::where('series', 'api_requests')
            ->where('timestamp', '>=', $startTime)
            ->orderBy('timestamp')
            ->get();

        $peakRps = 0;
        $peakTime = null;

        // Group by minute and calculate RPS for each minute
        $minuteGroups = $metrics->groupBy(function ($metric) {
            return $metric->timestamp->format('Y-m-d H:i');
        });

        foreach ($minuteGroups as $minute => $requests) {
            $rps = $requests->count() / 60; // requests per second
            
            if ($rps > $peakRps) {
                $peakRps = $rps;
                $peakTime = $minute;
            }
        }

        return [
            'peak_rps' => round($peakRps, 2),
            'peak_time' => $peakTime,
        ];
    }

    protected function calculatePeakResponseTime(\DateTime $startTime): array
    {
        $metrics = MonitoringMetric::where('series', 'api_requests')
            ->where('timestamp', '>=', $startTime)
            ->whereNotNull('data->response_time_ms')
            ->orderBy('data->response_time_ms', 'desc')
            ->limit(1)
            ->get();

        if ($metrics->isEmpty()) {
            return ['peak_response_time' => 0, 'endpoint' => null, 'time' => null];
        }

        $peakMetric = $metrics->first();
        
        return [
            'peak_response_time' => $peakMetric->data['response_time_ms'],
            'endpoint' => $peakMetric->data['endpoint'] ?? 'unknown',
            'time' => $peakMetric->timestamp->toISOString(),
        ];
    }

    protected function calculatePerformanceVariance(\DateTime $startTime): array
    {
        $metrics = MonitoringMetric::where('series', 'api_requests')
            ->where('timestamp', '>=', $startTime)
            ->whereNotNull('data->response_time_ms')
            ->get();

        if ($metrics->isEmpty()) {
            return ['variance' => 0, 'coefficient_of_variation' => 0];
        }

        $responseTimes = $metrics->pluck('data.response_time_ms')->toArray();
        $mean = array_sum($responseTimes) / count($responseTimes);
        $variance = $this->calculateStandardDeviation($responseTimes);
        $coefficientOfVariation = $mean > 0 ? ($variance / $mean) : 0;

        return [
            'variance' => round($variance, 2),
            'coefficient_of_variation' => round($coefficientOfVariation, 3),
            'stability_score' => round(max(0, 100 - ($coefficientOfVariation * 100)), 2),
        ];
    }

    protected function getMostCommonErrors($errorRequests): array
    {
        $errorCounts = [];

        foreach ($errorRequests as $request) {
            $statusCode = $request->data['status_code'] ?? 0;
            $endpoint = $request->data['endpoint'] ?? 'unknown';
            $key = "{$statusCode} - {$endpoint}";
            
            $errorCounts[$key] = ($errorCounts[$key] ?? 0) + 1;
        }

        arsort($errorCounts);
        
        return array_slice($errorCounts, 0, 10, true);
    }

    protected function getStatusCodeClass(int $statusCode): string
    {
        if ($statusCode >= 200 && $statusCode < 300) return '2xx';
        if ($statusCode >= 300 && $statusCode < 400) return '3xx';
        if ($statusCode >= 400 && $statusCode < 500) return '4xx';
        if ($statusCode >= 500) return '5xx';
        return '1xx';
    }

    protected function calculatePerformanceScore(array $stats): int
    {
        $responseTimeScore = max(0, 100 - ($stats['avg_response_time'] ?? 0) / 10);
        $errorRateScore = max(0, 100 - ($stats['error_rate'] ?? 0) * 20);
        
        return round(($responseTimeScore + $errorRateScore) / 2);
    }

    protected function getEmptyResponseTimeMetrics(): array
    {
        return [
            'avg' => 0,
            'min' => 0,
            'max' => 0,
            'median' => 0,
            'p95' => 0,
            'p99' => 0,
            'std_dev' => 0,
            'sample_count' => 0,
            'target_met' => 0,
        ];
    }

    protected function getEmptyErrorRateMetrics(): array
    {
        return [
            'total_error_rate' => 0,
            'server_error_rate' => 0,
            'client_error_rate' => 0,
            'error_count' => 0,
            'server_error_count' => 0,
            'client_error_count' => 0,
            'success_rate' => 100,
            'target_met' => true,
            'most_common_errors' => [],
        ];
    }

    protected function getEmptySlaMetrics(): array
    {
        return [
            'response_time_sla' => ['target' => '100ms', 'compliance_rate' => 0, 'met' => false],
            'availability_sla' => ['target' => '99.5%', 'compliance_rate' => 0, 'met' => false],
            'overall_sla_compliance' => ['met' => false, 'score' => 0],
        ];
    }
}