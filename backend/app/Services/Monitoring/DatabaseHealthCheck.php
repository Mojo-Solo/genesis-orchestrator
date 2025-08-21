<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Database Health Check
 * 
 * Performs comprehensive database health checks including
 * connectivity, performance, and configuration validation
 */
class DatabaseHealthCheck implements HealthCheckInterface
{
    protected array $requiredTables = [
        'users', 'tenants', 'meetings', 'transcripts', 
        'monitoring_metrics', 'system_alerts'
    ];

    /**
     * Execute database health check
     */
    public function execute(): array
    {
        $startTime = microtime(true);
        $checks = [];
        $overall = true;
        $critical = false;

        try {
            // Basic connectivity check
            $connectivityCheck = $this->checkConnectivity();
            $checks['connectivity'] = $connectivityCheck;
            if (!$connectivityCheck['healthy']) {
                $overall = false;
                $critical = true;
            }

            // Performance check
            $performanceCheck = $this->checkPerformance();
            $checks['performance'] = $performanceCheck;
            if (!$performanceCheck['healthy']) {
                $overall = false;
            }

            // Schema validation
            $schemaCheck = $this->checkSchema();
            $checks['schema'] = $schemaCheck;
            if (!$schemaCheck['healthy']) {
                $overall = false;
            }

            // Configuration check
            $configCheck = $this->checkConfiguration();
            $checks['configuration'] = $configCheck;
            if (!$configCheck['healthy']) {
                $overall = false;
            }

            // Storage check
            $storageCheck = $this->checkStorage();
            $checks['storage'] = $storageCheck;
            if (!$storageCheck['healthy']) {
                $overall = false;
                if ($storageCheck['critical'] ?? false) {
                    $critical = true;
                }
            }

            // Replication check (if applicable)
            $replicationCheck = $this->checkReplication();
            $checks['replication'] = $replicationCheck;
            if (!$replicationCheck['healthy'] && $replicationCheck['enabled']) {
                $overall = false;
            }

        } catch (\Exception $e) {
            Log::error('Database health check failed', [
                'error' => $e->getMessage(),
                'stack_trace' => $e->getTraceAsString(),
            ]);

            return [
                'healthy' => false,
                'critical' => true,
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ];
        }

        return [
            'healthy' => $overall,
            'critical' => $critical,
            'checks' => $checks,
            'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'summary' => $this->generateSummary($checks),
        ];
    }

    /**
     * Check basic database connectivity
     */
    protected function checkConnectivity(): array
    {
        try {
            $start = microtime(true);
            $result = DB::select('SELECT 1 as test');
            $responseTime = (microtime(true) - $start) * 1000;

            if (empty($result) || $result[0]->test !== 1) {
                return [
                    'healthy' => false,
                    'message' => 'Database query returned unexpected result',
                    'response_time_ms' => $responseTime,
                ];
            }

            return [
                'healthy' => true,
                'message' => 'Database connectivity OK',
                'response_time_ms' => round($responseTime, 2),
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'message' => 'Database connection failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check database performance
     */
    protected function checkPerformance(): array
    {
        try {
            $checks = [];

            // Query response time check
            $start = microtime(true);
            DB::select('SELECT COUNT(*) as count FROM users LIMIT 1');
            $queryTime = (microtime(true) - $start) * 1000;

            $checks['query_response_time'] = [
                'value' => round($queryTime, 2),
                'healthy' => $queryTime < 100, // 100ms threshold
                'threshold' => 100,
            ];

            // Connection pool check
            $processlist = DB::select('SHOW PROCESSLIST');
            $connectionCount = count($processlist);
            $maxConnections = $this->getMaxConnections();

            $checks['connection_usage'] = [
                'current' => $connectionCount,
                'max' => $maxConnections,
                'utilization_percent' => round(($connectionCount / $maxConnections) * 100, 2),
                'healthy' => ($connectionCount / $maxConnections) < 0.8, // 80% threshold
            ];

            // Slow query analysis
            $slowQueries = $this->getSlowQueryCount();
            $checks['slow_queries'] = [
                'count' => $slowQueries,
                'healthy' => $slowQueries < 10, // 10 slow queries threshold
                'threshold' => 10,
            ];

            $overallHealthy = collect($checks)->every(fn($check) => $check['healthy']);

            return [
                'healthy' => $overallHealthy,
                'checks' => $checks,
                'message' => $overallHealthy ? 'Database performance OK' : 'Performance issues detected',
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'message' => 'Performance check failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check database schema integrity
     */
    protected function checkSchema(): array
    {
        try {
            $missingTables = [];
            $corruptedTables = [];

            foreach ($this->requiredTables as $table) {
                try {
                    // Check if table exists
                    $exists = DB::select("SHOW TABLES LIKE '{$table}'");
                    if (empty($exists)) {
                        $missingTables[] = $table;
                        continue;
                    }

                    // Check table integrity
                    $checkResult = DB::select("CHECK TABLE {$table}");
                    foreach ($checkResult as $result) {
                        if ($result->Msg_text !== 'OK') {
                            $corruptedTables[] = [
                                'table' => $table,
                                'status' => $result->Msg_text,
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    $corruptedTables[] = [
                        'table' => $table,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            $healthy = empty($missingTables) && empty($corruptedTables);

            return [
                'healthy' => $healthy,
                'missing_tables' => $missingTables,
                'corrupted_tables' => $corruptedTables,
                'total_tables_checked' => count($this->requiredTables),
                'message' => $healthy ? 'Schema integrity OK' : 'Schema issues detected',
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'message' => 'Schema check failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check database configuration
     */
    protected function checkConfiguration(): array
    {
        try {
            $variables = collect(DB::select('SHOW VARIABLES'))->keyBy('Variable_name');
            $issues = [];
            $warnings = [];

            // Check important configuration values
            $configs = [
                'innodb_buffer_pool_size' => [
                    'min_mb' => 128,
                    'recommended_mb' => 512,
                ],
                'max_connections' => [
                    'min' => 100,
                    'recommended' => 200,
                ],
                'query_cache_size' => [
                    'min_mb' => 16,
                    'recommended_mb' => 64,
                ],
            ];

            foreach ($configs as $varName => $limits) {
                $value = $variables->get($varName)?->Value ?? 0;
                
                if (strpos($varName, 'size') !== false) {
                    $valueMb = $value / 1024 / 1024;
                    $minMb = $limits['min_mb'] ?? 0;
                    $recMb = $limits['recommended_mb'] ?? 0;

                    if ($valueMb < $minMb) {
                        $issues[] = "{$varName} is too low: {$valueMb}MB (min: {$minMb}MB)";
                    } elseif ($valueMb < $recMb) {
                        $warnings[] = "{$varName} could be higher: {$valueMb}MB (recommended: {$recMb}MB)";
                    }
                } else {
                    $min = $limits['min'] ?? 0;
                    $rec = $limits['recommended'] ?? 0;

                    if ($value < $min) {
                        $issues[] = "{$varName} is too low: {$value} (min: {$min})";
                    } elseif ($value < $rec) {
                        $warnings[] = "{$varName} could be higher: {$value} (recommended: {$rec})";
                    }
                }
            }

            // Check SQL mode
            $sqlMode = $variables->get('sql_mode')?->Value ?? '';
            if (strpos($sqlMode, 'STRICT_TRANS_TABLES') === false) {
                $warnings[] = 'SQL mode should include STRICT_TRANS_TABLES for data integrity';
            }

            return [
                'healthy' => empty($issues),
                'issues' => $issues,
                'warnings' => $warnings,
                'message' => empty($issues) ? 'Configuration OK' : 'Configuration issues detected',
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'message' => 'Configuration check failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check database storage and disk space
     */
    protected function checkStorage(): array
    {
        try {
            // Get database size
            $sizeQuery = DB::select("
                SELECT 
                    SUM(data_length + index_length) as total_size,
                    SUM(data_free) as free_space
                FROM information_schema.tables 
                WHERE table_schema = DATABASE()
            ");

            $size = $sizeQuery[0] ?? null;
            $totalSizeMb = round(($size->total_size ?? 0) / 1024 / 1024, 2);
            $freeSpaceMb = round(($size->free_space ?? 0) / 1024 / 1024, 2);

            // Check tablespace usage
            $tablespaceQuery = DB::select("
                SELECT 
                    tablespace_name,
                    file_name,
                    total_extents,
                    extent_size,
                    (total_extents * extent_size) as size_bytes
                FROM information_schema.files 
                WHERE file_type = 'DATAFILE'
                LIMIT 10
            ");

            // Estimate growth rate (simplified)
            $growthRate = $this->estimateGrowthRate();

            $critical = false;
            $healthy = true;
            $warnings = [];

            // Check if database is growing too fast
            if ($growthRate['daily_mb'] > 100) {
                $warnings[] = "High growth rate detected: {$growthRate['daily_mb']}MB/day";
                $healthy = false;
            }

            // Check for very large database
            if ($totalSizeMb > 10000) { // 10GB
                $warnings[] = "Large database size: {$totalSizeMb}MB";
            }

            return [
                'healthy' => $healthy,
                'critical' => $critical,
                'total_size_mb' => $totalSizeMb,
                'free_space_mb' => $freeSpaceMb,
                'growth_rate' => $growthRate,
                'tablespaces' => $tablespaceQuery,
                'warnings' => $warnings,
                'message' => $healthy ? 'Storage OK' : 'Storage issues detected',
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'message' => 'Storage check failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check replication status
     */
    protected function checkReplication(): array
    {
        try {
            $slaveStatus = DB::select('SHOW SLAVE STATUS');
            
            if (empty($slaveStatus)) {
                return [
                    'healthy' => true,
                    'enabled' => false,
                    'message' => 'Replication not configured',
                ];
            }

            $status = $slaveStatus[0];
            $healthy = true;
            $issues = [];

            // Check if replication is running
            if ($status->Slave_IO_Running !== 'Yes') {
                $healthy = false;
                $issues[] = 'Slave IO thread not running';
            }

            if ($status->Slave_SQL_Running !== 'Yes') {
                $healthy = false;
                $issues[] = 'Slave SQL thread not running';
            }

            // Check replication lag
            $secondsBehind = $status->Seconds_Behind_Master ?? 0;
            if ($secondsBehind > 60) {
                $healthy = false;
                $issues[] = "High replication lag: {$secondsBehind} seconds";
            }

            // Check for errors
            if (!empty($status->Last_Error)) {
                $healthy = false;
                $issues[] = "Replication error: {$status->Last_Error}";
            }

            return [
                'healthy' => $healthy,
                'enabled' => true,
                'io_running' => $status->Slave_IO_Running === 'Yes',
                'sql_running' => $status->Slave_SQL_Running === 'Yes',
                'seconds_behind_master' => $secondsBehind,
                'last_error' => $status->Last_Error ?? null,
                'issues' => $issues,
                'message' => $healthy ? 'Replication OK' : 'Replication issues detected',
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'enabled' => false,
                'message' => 'Replication check failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    // Helper methods

    protected function getMaxConnections(): int
    {
        try {
            $result = DB::select("SHOW VARIABLES LIKE 'max_connections'");
            return (int) ($result[0]->Value ?? 151);
        } catch (\Exception $e) {
            return 151; // Default MySQL value
        }
    }

    protected function getSlowQueryCount(): int
    {
        try {
            $result = DB::select("SHOW STATUS LIKE 'Slow_queries'");
            return (int) ($result[0]->Value ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    protected function estimateGrowthRate(): array
    {
        // This would typically analyze historical data
        // For now, return mock data
        return [
            'daily_mb' => 5.2,
            'weekly_mb' => 36.4,
            'monthly_mb' => 156.0,
            'trend' => 'stable',
        ];
    }

    protected function generateSummary(array $checks): array
    {
        $healthyCount = 0;
        $totalChecks = 0;
        $criticalIssues = 0;
        $warnings = 0;

        foreach ($checks as $checkName => $checkResult) {
            $totalChecks++;
            
            if ($checkResult['healthy']) {
                $healthyCount++;
            } else {
                if ($checkResult['critical'] ?? false) {
                    $criticalIssues++;
                } else {
                    $warnings++;
                }
            }
        }

        return [
            'total_checks' => $totalChecks,
            'healthy_checks' => $healthyCount,
            'critical_issues' => $criticalIssues,
            'warnings' => $warnings,
            'health_score' => round(($healthyCount / $totalChecks) * 100, 2),
        ];
    }
}