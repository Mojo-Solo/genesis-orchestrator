<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\MonitoringMetric;

/**
 * Database Performance Metrics Collector
 * 
 * Monitors database performance including query times,
 * connection usage, slow queries, and database health
 */
class DatabasePerformanceCollector implements MetricCollectorInterface
{
    /**
     * Collect database performance metrics
     */
    public function collect(array $options = []): array
    {
        $timeWindow = $options['time_window'] ?? '5m';
        $startTime = $this->getStartTime($timeWindow);

        return [
            'query_performance' => $this->getQueryPerformanceMetrics($startTime),
            'connection_metrics' => $this->getConnectionMetrics(),
            'slow_queries' => $this->getSlowQueryAnalysis($startTime),
            'table_statistics' => $this->getTableStatistics(),
            'database_size' => $this->getDatabaseSizeMetrics(),
            'replication_status' => $this->getReplicationStatus(),
            'lock_analysis' => $this->getLockAnalysis(),
            'cache_performance' => $this->getCachePerformanceMetrics(),
        ];
    }

    /**
     * Get query performance metrics
     */
    protected function getQueryPerformanceMetrics(\DateTime $startTime): array
    {
        $cacheKey = "db_query_performance_" . $startTime->getTimestamp();
        
        return Cache::remember($cacheKey, 60, function () use ($startTime) {
            $metrics = MonitoringMetric::where('series', 'database_queries')
                ->where('timestamp', '>=', $startTime)
                ->get();

            if ($metrics->isEmpty()) {
                return $this->getEmptyQueryMetrics();
            }

            $executionTimes = $metrics->pluck('data.execution_time_ms')->filter();
            $queryCount = $metrics->count();

            return [
                'total_queries' => $queryCount,
                'avg_execution_time' => round($executionTimes->avg(), 2),
                'min_execution_time' => $executionTimes->min(),
                'max_execution_time' => $executionTimes->max(),
                'p95_execution_time' => $this->calculatePercentile($executionTimes->toArray(), 95),
                'p99_execution_time' => $this->calculatePercentile($executionTimes->toArray(), 99),
                'queries_per_second' => round($queryCount / max(1, now()->diffInSeconds($startTime)), 2),
                'slow_query_count' => $executionTimes->filter(fn($time) => $time > 1000)->count(),
                'slow_query_percentage' => round(($executionTimes->filter(fn($time) => $time > 1000)->count() / $queryCount) * 100, 2),
                'performance_score' => $this->calculateQueryPerformanceScore($executionTimes->toArray()),
            ];
        });
    }

    /**
     * Get database connection metrics
     */
    protected function getConnectionMetrics(): array
    {
        try {
            // Get MySQL connection statistics
            $processlist = DB::select('SHOW PROCESSLIST');
            $status = collect(DB::select('SHOW STATUS'))->keyBy('Variable_name');
            $variables = collect(DB::select('SHOW VARIABLES'))->keyBy('Variable_name');

            $maxConnections = (int) $variables->get('max_connections')?->Value ?? 151;
            $currentConnections = count($processlist);
            $threadsConnected = (int) $status->get('Threads_connected')?->Value ?? 0;
            $threadsRunning = (int) $status->get('Threads_running')?->Value ?? 0;

            return [
                'max_connections' => $maxConnections,
                'current_connections' => $currentConnections,
                'threads_connected' => $threadsConnected,
                'threads_running' => $threadsRunning,
                'connection_utilization' => round(($currentConnections / $maxConnections) * 100, 2),
                'active_connections' => $threadsRunning,
                'idle_connections' => max(0, $threadsConnected - $threadsRunning),
                'connections_per_second' => (int) $status->get('Connections')?->Value ?? 0,
                'aborted_connections' => (int) $status->get('Aborted_connects')?->Value ?? 0,
                'health_status' => $this->assessConnectionHealth($currentConnections, $maxConnections, $threadsRunning),
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Failed to collect connection metrics: ' . $e->getMessage(),
                'health_status' => 'error',
            ];
        }
    }

    /**
     * Analyze slow queries
     */
    protected function getSlowQueryAnalysis(\DateTime $startTime): array
    {
        $slowQueries = MonitoringMetric::where('series', 'database_queries')
            ->where('timestamp', '>=', $startTime)
            ->whereRaw("(data->>'execution_time_ms')::numeric > 1000")
            ->orderByRaw("(data->>'execution_time_ms')::numeric DESC")
            ->limit(20)
            ->get();

        $analysis = [
            'total_slow_queries' => $slowQueries->count(),
            'slowest_queries' => [],
            'query_patterns' => [],
            'recommendations' => [],
        ];

        foreach ($slowQueries as $query) {
            $analysis['slowest_queries'][] = [
                'query_signature' => $query->data['query_signature'] ?? 'unknown',
                'execution_time_ms' => $query->data['execution_time_ms'] ?? 0,
                'timestamp' => $query->timestamp->toISOString(),
            ];
        }

        // Analyze query patterns
        $patternCounts = [];
        foreach ($slowQueries as $query) {
            $signature = $query->data['query_signature'] ?? 'unknown';
            $patternCounts[$signature] = ($patternCounts[$signature] ?? 0) + 1;
        }

        arsort($patternCounts);
        $analysis['query_patterns'] = array_slice($patternCounts, 0, 10, true);

        // Generate recommendations
        $analysis['recommendations'] = $this->generateSlowQueryRecommendations($analysis);

        return $analysis;
    }

    /**
     * Get table statistics and sizes
     */
    protected function getTableStatistics(): array
    {
        try {
            $tableStats = DB::select("
                SELECT 
                    table_name,
                    table_rows,
                    data_length,
                    index_length,
                    (data_length + index_length) as total_size,
                    auto_increment
                FROM information_schema.tables 
                WHERE table_schema = DATABASE()
                ORDER BY (data_length + index_length) DESC
                LIMIT 20
            ");

            $analysis = [
                'largest_tables' => [],
                'total_size_bytes' => 0,
                'total_rows' => 0,
                'index_efficiency' => [],
            ];

            foreach ($tableStats as $table) {
                $totalSize = $table->total_size ?? 0;
                $dataSize = $table->data_length ?? 0;
                $indexSize = $table->index_length ?? 0;

                $analysis['largest_tables'][] = [
                    'table_name' => $table->table_name,
                    'rows' => $table->table_rows ?? 0,
                    'data_size_mb' => round($dataSize / 1024 / 1024, 2),
                    'index_size_mb' => round($indexSize / 1024 / 1024, 2),
                    'total_size_mb' => round($totalSize / 1024 / 1024, 2),
                    'index_ratio' => $dataSize > 0 ? round($indexSize / $dataSize, 2) : 0,
                ];

                $analysis['total_size_bytes'] += $totalSize;
                $analysis['total_rows'] += $table->table_rows ?? 0;
            }

            return $analysis;
        } catch (\Exception $e) {
            return [
                'error' => 'Failed to collect table statistics: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get database size metrics
     */
    protected function getDatabaseSizeMetrics(): array
    {
        try {
            $sizeQuery = DB::select("
                SELECT 
                    SUM(data_length + index_length) as total_size,
                    SUM(data_length) as data_size,
                    SUM(index_length) as index_size,
                    COUNT(*) as table_count
                FROM information_schema.tables 
                WHERE table_schema = DATABASE()
            ");

            $size = $sizeQuery[0] ?? null;
            
            if (!$size) {
                return ['error' => 'Unable to determine database size'];
            }

            return [
                'total_size_bytes' => $size->total_size ?? 0,
                'total_size_mb' => round(($size->total_size ?? 0) / 1024 / 1024, 2),
                'total_size_gb' => round(($size->total_size ?? 0) / 1024 / 1024 / 1024, 2),
                'data_size_mb' => round(($size->data_size ?? 0) / 1024 / 1024, 2),
                'index_size_mb' => round(($size->index_size ?? 0) / 1024 / 1024, 2),
                'table_count' => $size->table_count ?? 0,
                'growth_trend' => $this->calculateDatabaseGrowthTrend(),
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Failed to collect database size metrics: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get replication status (if applicable)
     */
    protected function getReplicationStatus(): array
    {
        try {
            $replicationInfo = DB::select('SHOW SLAVE STATUS');
            
            if (empty($replicationInfo)) {
                return [
                    'enabled' => false,
                    'status' => 'not_configured',
                ];
            }

            $status = $replicationInfo[0];
            
            return [
                'enabled' => true,
                'slave_io_running' => $status->Slave_IO_Running === 'Yes',
                'slave_sql_running' => $status->Slave_SQL_Running === 'Yes',
                'seconds_behind_master' => $status->Seconds_Behind_Master ?? null,
                'master_host' => $status->Master_Host ?? null,
                'last_error' => $status->Last_Error ?? null,
                'health_status' => $this->assessReplicationHealth($status),
            ];
        } catch (\Exception $e) {
            return [
                'enabled' => false,
                'error' => 'Failed to check replication status: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Analyze database locks
     */
    protected function getLockAnalysis(): array
    {
        try {
            $lockInfo = DB::select("
                SELECT 
                    COUNT(*) as total_locks,
                    COUNT(DISTINCT object_name) as locked_objects,
                    lock_type,
                    lock_mode,
                    COUNT(*) as count
                FROM performance_schema.data_locks
                GROUP BY lock_type, lock_mode
                ORDER BY count DESC
            ");

            $waitingLocks = DB::select("
                SELECT COUNT(*) as waiting_locks
                FROM performance_schema.data_lock_waits
            ");

            return [
                'total_locks' => array_sum(array_column($lockInfo, 'count')),
                'waiting_locks' => $waitingLocks[0]->waiting_locks ?? 0,
                'lock_distribution' => $lockInfo,
                'lock_contention_score' => $this->calculateLockContentionScore($lockInfo, $waitingLocks),
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Failed to analyze locks: ' . $e->getMessage(),
                'lock_contention_score' => 'unknown',
            ];
        }
    }

    /**
     * Get database cache performance
     */
    protected function getCachePerformanceMetrics(): array
    {
        try {
            $status = collect(DB::select('SHOW STATUS'))->keyBy('Variable_name');

            $queryCache = [
                'hits' => (int) $status->get('Qcache_hits')?->Value ?? 0,
                'inserts' => (int) $status->get('Qcache_inserts')?->Value ?? 0,
                'not_cached' => (int) $status->get('Qcache_not_cached')?->Value ?? 0,
                'size' => (int) $status->get('Qcache_total_blocks')?->Value ?? 0,
            ];

            $totalQueries = $queryCache['hits'] + $queryCache['inserts'] + $queryCache['not_cached'];
            $hitRate = $totalQueries > 0 ? ($queryCache['hits'] / $totalQueries) * 100 : 0;

            $bufferPool = [
                'size' => (int) $status->get('Innodb_buffer_pool_pages_total')?->Value ?? 0,
                'free' => (int) $status->get('Innodb_buffer_pool_pages_free')?->Value ?? 0,
                'dirty' => (int) $status->get('Innodb_buffer_pool_pages_dirty')?->Value ?? 0,
                'reads' => (int) $status->get('Innodb_buffer_pool_reads')?->Value ?? 0,
                'read_requests' => (int) $status->get('Innodb_buffer_pool_read_requests')?->Value ?? 0,
            ];

            $bufferHitRate = $bufferPool['read_requests'] > 0 ? 
                (($bufferPool['read_requests'] - $bufferPool['reads']) / $bufferPool['read_requests']) * 100 : 0;

            return [
                'query_cache' => [
                    'hit_rate' => round($hitRate, 2),
                    'hits' => $queryCache['hits'],
                    'misses' => $queryCache['inserts'],
                    'not_cached' => $queryCache['not_cached'],
                ],
                'buffer_pool' => [
                    'hit_rate' => round($bufferHitRate, 2),
                    'utilization' => round((($bufferPool['size'] - $bufferPool['free']) / max(1, $bufferPool['size'])) * 100, 2),
                    'dirty_pages' => $bufferPool['dirty'],
                    'total_pages' => $bufferPool['size'],
                ],
                'cache_efficiency_score' => $this->calculateCacheEfficiencyScore($hitRate, $bufferHitRate),
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Failed to collect cache metrics: ' . $e->getMessage(),
            ];
        }
    }

    // Helper methods

    protected function getStartTime(string $timeWindow): \DateTime
    {
        $intervals = [
            '1m' => 1, '5m' => 5, '15m' => 15, '30m' => 30,
            '1h' => 60, '3h' => 180, '6h' => 360, '12h' => 720, '24h' => 1440,
        ];

        $minutes = $intervals[$timeWindow] ?? 5;
        return now()->subMinutes($minutes);
    }

    protected function calculatePercentile(array $values, int $percentile): float
    {
        if (empty($values)) return 0.0;

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

    protected function calculateQueryPerformanceScore(array $executionTimes): int
    {
        if (empty($executionTimes)) return 100;

        $avg = array_sum($executionTimes) / count($executionTimes);
        $slowQueries = count(array_filter($executionTimes, fn($time) => $time > 1000));
        $slowPercentage = ($slowQueries / count($executionTimes)) * 100;

        // Score based on average execution time and slow query percentage
        $avgScore = max(0, 100 - ($avg / 10));
        $slowScore = max(0, 100 - ($slowPercentage * 5));

        return round(($avgScore + $slowScore) / 2);
    }

    protected function assessConnectionHealth(int $current, int $max, int $running): string
    {
        $utilization = ($current / $max) * 100;
        $activeRatio = $current > 0 ? ($running / $current) * 100 : 0;

        if ($utilization > 90) return 'critical';
        if ($utilization > 75 || $activeRatio > 80) return 'warning';
        return 'healthy';
    }

    protected function generateSlowQueryRecommendations(array $analysis): array
    {
        $recommendations = [];

        if ($analysis['total_slow_queries'] > 10) {
            $recommendations[] = "High number of slow queries detected. Consider query optimization and indexing.";
        }

        foreach ($analysis['query_patterns'] as $pattern => $count) {
            if ($count > 5) {
                $recommendations[] = "Query pattern '{$pattern}' appears frequently in slow queries. Consider optimization.";
            }
        }

        if (empty($recommendations)) {
            $recommendations[] = "Query performance looks good. Continue monitoring.";
        }

        return $recommendations;
    }

    protected function calculateDatabaseGrowthTrend(): array
    {
        // This would typically compare current size with historical data
        // For now, return placeholder data
        return [
            'daily_growth_mb' => 0,
            'weekly_growth_mb' => 0,
            'projected_size_30_days' => 0,
            'trend' => 'stable',
        ];
    }

    protected function assessReplicationHealth($status): string
    {
        if ($status->Slave_IO_Running !== 'Yes' || $status->Slave_SQL_Running !== 'Yes') {
            return 'critical';
        }

        if (($status->Seconds_Behind_Master ?? 0) > 60) {
            return 'warning';
        }

        return 'healthy';
    }

    protected function calculateLockContentionScore(array $lockInfo, array $waitingLocks): int
    {
        $totalLocks = array_sum(array_column($lockInfo, 'count'));
        $waitingCount = $waitingLocks[0]->waiting_locks ?? 0;

        if ($totalLocks === 0) return 100;

        $contentionRatio = ($waitingCount / $totalLocks) * 100;
        return max(0, round(100 - ($contentionRatio * 10)));
    }

    protected function calculateCacheEfficiencyScore(float $queryHitRate, float $bufferHitRate): int
    {
        return round(($queryHitRate + $bufferHitRate) / 2);
    }

    protected function getEmptyQueryMetrics(): array
    {
        return [
            'total_queries' => 0,
            'avg_execution_time' => 0,
            'min_execution_time' => 0,
            'max_execution_time' => 0,
            'p95_execution_time' => 0,
            'p99_execution_time' => 0,
            'queries_per_second' => 0,
            'slow_query_count' => 0,
            'slow_query_percentage' => 0,
            'performance_score' => 100,
        ];
    }
}