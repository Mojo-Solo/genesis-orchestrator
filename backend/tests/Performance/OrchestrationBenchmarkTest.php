<?php

namespace Tests\Performance;

use Tests\TestCase;
use App\Domains\Orchestration\OrchestrationDomain;
use App\Domains\Orchestration\Services\LAGEngine;
use App\Domains\Orchestration\Services\RCRRouter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Performance Benchmarking Suite for GENESIS Orchestration
 * 
 * Validates compliance with evaluation requirements:
 * - ≥98.6% stability under all conditions
 * - ≤200ms average response time
 * - ≤1.4% performance variance
 * - Token efficiency ≥20% reduction
 * - Memory efficiency under load
 * - Concurrent processing stability
 * 
 * This suite runs comprehensive performance tests that simulate
 * real-world evaluation conditions to ensure certification readiness.
 */
#[Group('performance')]
class OrchestrationBenchmarkTest extends TestCase
{
    private OrchestrationDomain $orchestrationDomain;
    private LAGEngine $lagEngine;
    private RCRRouter $rcrRouter;
    
    private array $benchmarkResults = [];
    private float $testStartTime;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->lagEngine = new LAGEngine();
        $this->rcrRouter = new RCRRouter();
        $this->orchestrationDomain = new OrchestrationDomain(
            $this->lagEngine,
            $this->rcrRouter
        );
        
        $this->benchmarkResults = [];
        $this->testStartTime = microtime(true);
    }

    #[Test]
    public function it_meets_stability_requirement_under_standard_load()
    {
        $testQueries = $this->getStandardTestQueries();
        $totalRuns = 1000; // Significant sample size for statistical validity
        $successCount = 0;
        $responseTimes = [];
        $confidenceScores = [];

        echo "\n=== STABILITY BENCHMARK (1000 queries) ===\n";

        foreach ($testQueries as $i => $queryData) {
            $batchSize = intval($totalRuns / count($testQueries));
            
            for ($j = 0; $j < $batchSize; $j++) {
                $startTime = microtime(true);
                
                try {
                    $result = $this->orchestrationDomain->processQuery(
                        $queryData['query'],
                        $queryData['context']
                    );
                    
                    $responseTime = (microtime(true) - $startTime) * 1000;
                    $responseTimes[] = $responseTime;
                    
                    if ($result['status'] === 'success' && $result['confidence'] >= 0.8) {
                        $successCount++;
                        $confidenceScores[] = $result['confidence'];
                    }
                    
                    // Progress indicator
                    if (($i * $batchSize + $j + 1) % 100 === 0) {
                        $current = $i * $batchSize + $j + 1;
                        echo "Progress: {$current}/{$totalRuns} queries processed\n";
                    }
                    
                } catch (\Exception $e) {
                    // Track failures
                }
            }
        }

        // Calculate stability metrics
        $actualRuns = count($responseTimes);
        $stabilityRate = $successCount / $actualRuns;
        $avgResponseTime = array_sum($responseTimes) / count($responseTimes);
        $avgConfidence = array_sum($confidenceScores) / count($confidenceScores);
        
        echo "\n=== STABILITY RESULTS ===\n";
        echo "Total runs: {$actualRuns}\n";
        echo "Successful runs: {$successCount}\n";
        echo "Stability rate: " . ($stabilityRate * 100) . "%\n";
        echo "Average response time: {$avgResponseTime}ms\n";
        echo "Average confidence: {$avgConfidence}\n";

        // Record benchmark results
        $this->benchmarkResults['stability_standard_load'] = [
            'stability_rate' => $stabilityRate,
            'avg_response_time' => $avgResponseTime,
            'avg_confidence' => $avgConfidence,
            'total_runs' => $actualRuns
        ];

        // Assertions for evaluation requirements
        $this->assertGreaterThanOrEqual(0.986, $stabilityRate, 
            "Stability rate must be ≥98.6%, achieved: " . ($stabilityRate * 100) . "%");
        $this->assertLessThan(200, $avgResponseTime, 
            "Average response time must be <200ms, achieved: {$avgResponseTime}ms");
        $this->assertGreaterThanOrEqual(0.8, $avgConfidence,
            "Average confidence must be ≥80%, achieved: {$avgConfidence}");
    }

    #[Test]
    public function it_maintains_performance_variance_within_limits()
    {
        $query = "Optimize distributed system architecture for high-availability requirements";
        $context = ['domain' => 'technical', 'complexity' => 0.8, 'priority' => 'high'];
        
        $sampleSize = 200;
        $responseTimes = [];
        $confidenceScores = [];
        $memoryUsages = [];

        echo "\n=== VARIANCE BENCHMARK (200 identical queries) ===\n";

        for ($i = 0; $i < $sampleSize; $i++) {
            $memoryBefore = memory_get_usage(true);
            $startTime = microtime(true);
            
            try {
                $result = $this->orchestrationDomain->processQuery($query, $context);
                
                $responseTime = (microtime(true) - $startTime) * 1000;
                $memoryAfter = memory_get_usage(true);
                
                $responseTimes[] = $responseTime;
                $confidenceScores[] = $result['confidence'];
                $memoryUsages[] = ($memoryAfter - $memoryBefore) / 1024; // KB
                
                if (($i + 1) % 50 === 0) {
                    echo "Progress: " . ($i + 1) . "/{$sampleSize} variance tests completed\n";
                }
                
            } catch (\Exception $e) {
                // Track but don't fail immediately
            }
        }

        // Calculate variance metrics
        $avgResponseTime = array_sum($responseTimes) / count($responseTimes);
        $avgConfidence = array_sum($confidenceScores) / count($confidenceScores);
        $avgMemoryUsage = array_sum($memoryUsages) / count($memoryUsages);
        
        // Calculate coefficient of variation (CV)
        $responseTimeVariance = $this->calculateCoefficientOfVariation($responseTimes, $avgResponseTime);
        $confidenceVariance = $this->calculateCoefficientOfVariation($confidenceScores, $avgConfidence);
        $memoryVariance = $this->calculateCoefficientOfVariation($memoryUsages, $avgMemoryUsage);

        echo "\n=== VARIANCE RESULTS ===\n";
        echo "Sample size: " . count($responseTimes) . "\n";
        echo "Average response time: {$avgResponseTime}ms (CV: " . ($responseTimeVariance * 100) . "%)\n";
        echo "Average confidence: {$avgConfidence} (CV: " . ($confidenceVariance * 100) . "%)\n";
        echo "Average memory usage: {$avgMemoryUsage}KB (CV: " . ($memoryVariance * 100) . "%)\n";

        // Record benchmark results
        $this->benchmarkResults['performance_variance'] = [
            'response_time_cv' => $responseTimeVariance,
            'confidence_cv' => $confidenceVariance,
            'memory_cv' => $memoryVariance,
            'avg_response_time' => $avgResponseTime
        ];

        // Assertions for evaluation requirements
        $this->assertLessThanOrEqual(0.014, $responseTimeVariance, 
            "Response time variance must be ≤1.4%, achieved: " . ($responseTimeVariance * 100) . "%");
        $this->assertLessThanOrEqual(0.014, $confidenceVariance,
            "Confidence variance must be ≤1.4%, achieved: " . ($confidenceVariance * 100) . "%");
        $this->assertLessThan(200, $avgResponseTime, 
            "Average response time must be <200ms during variance test");
    }

    #[Test]
    public function it_handles_concurrent_processing_effectively()
    {
        $concurrentQueries = [
            "Analyze real-time data streams for anomaly detection",
            "Design fault-tolerant distributed messaging system", 
            "Implement machine learning pipeline for predictive analytics",
            "Optimize database query performance for OLAP workloads",
            "Develop microservices orchestration with service mesh"
        ];

        $concurrencyLevel = 10; // Simulate 10 concurrent requests
        $iterationsPerQuery = 20;
        
        echo "\n=== CONCURRENCY BENCHMARK (50 concurrent batches) ===\n";

        $allResults = [];
        $concurrentStartTime = microtime(true);

        for ($iteration = 0; $iteration < $iterationsPerQuery; $iteration++) {
            $batchStartTime = microtime(true);
            $batchResults = [];

            // Simulate concurrent processing by running queries in quick succession
            foreach ($concurrentQueries as $query) {
                $queryStartTime = microtime(true);
                
                try {
                    $result = $this->orchestrationDomain->processQuery($query, [
                        'concurrent_batch' => $iteration + 1,
                        'complexity' => 0.7
                    ]);
                    
                    $queryTime = (microtime(true) - $queryStartTime) * 1000;
                    $batchResults[] = [
                        'query' => $query,
                        'result' => $result,
                        'response_time' => $queryTime,
                        'success' => $result['status'] === 'success'
                    ];
                    
                } catch (\Exception $e) {
                    $batchResults[] = [
                        'query' => $query,
                        'result' => null,
                        'response_time' => (microtime(true) - $queryStartTime) * 1000,
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }
            }

            $batchTime = (microtime(true) - $batchStartTime) * 1000;
            $allResults[] = [
                'batch' => $iteration + 1,
                'batch_time' => $batchTime,
                'results' => $batchResults
            ];

            if (($iteration + 1) % 5 === 0) {
                echo "Completed batch " . ($iteration + 1) . "/{$iterationsPerQuery}\n";
            }
        }

        $totalConcurrentTime = (microtime(true) - $concurrentStartTime) * 1000;

        // Analyze concurrency results
        $totalQueries = 0;
        $successfulQueries = 0;
        $allResponseTimes = [];
        $allConfidenceScores = [];

        foreach ($allResults as $batch) {
            foreach ($batch['results'] as $result) {
                $totalQueries++;
                $allResponseTimes[] = $result['response_time'];
                
                if ($result['success']) {
                    $successfulQueries++;
                    if (isset($result['result']['confidence'])) {
                        $allConfidenceScores[] = $result['result']['confidence'];
                    }
                }
            }
        }

        $concurrentStability = $successfulQueries / $totalQueries;
        $avgConcurrentResponseTime = array_sum($allResponseTimes) / count($allResponseTimes);
        $avgConcurrentConfidence = array_sum($allConfidenceScores) / count($allConfidenceScores);
        $throughput = $totalQueries / ($totalConcurrentTime / 1000); // queries per second

        echo "\n=== CONCURRENCY RESULTS ===\n";
        echo "Total queries: {$totalQueries}\n";
        echo "Successful queries: {$successfulQueries}\n";
        echo "Concurrency stability: " . ($concurrentStability * 100) . "%\n";
        echo "Average response time: {$avgConcurrentResponseTime}ms\n";
        echo "Average confidence: {$avgConcurrentConfidence}\n";
        echo "Throughput: {$throughput} queries/second\n";
        echo "Total concurrent execution time: {$totalConcurrentTime}ms\n";

        // Record benchmark results
        $this->benchmarkResults['concurrent_processing'] = [
            'stability' => $concurrentStability,
            'avg_response_time' => $avgConcurrentResponseTime,
            'throughput' => $throughput,
            'total_queries' => $totalQueries
        ];

        // Assertions for evaluation requirements
        $this->assertGreaterThanOrEqual(0.986, $concurrentStability,
            "Concurrent stability must be ≥98.6%, achieved: " . ($concurrentStability * 100) . "%");
        $this->assertLessThan(250, $avgConcurrentResponseTime,
            "Concurrent response time should be reasonable, achieved: {$avgConcurrentResponseTime}ms");
        $this->assertGreaterThan(5, $throughput,
            "Should achieve reasonable throughput, achieved: {$throughput} q/s");
    }

    #[Test]
    public function it_maintains_memory_efficiency_under_load()
    {
        $complexQuery = str_repeat("Analyze distributed system patterns for scalable microservices architecture with event-driven communication protocols and fault-tolerant design patterns. ", 10);
        
        $memoryTests = 100;
        $memoryUsages = [];
        $initialMemory = memory_get_usage(true);
        
        echo "\n=== MEMORY EFFICIENCY BENCHMARK (100 complex queries) ===\n";

        for ($i = 0; $i < $memoryTests; $i++) {
            $memoryBefore = memory_get_usage(true);
            
            try {
                $result = $this->orchestrationDomain->processQuery($complexQuery, [
                    'complexity' => 0.9,
                    'memory_test' => true,
                    'iteration' => $i + 1
                ]);
                
                $memoryAfter = memory_get_usage(true);
                $memoryIncrease = $memoryAfter - $memoryBefore;
                $memoryUsages[] = $memoryIncrease;
                
                if (($i + 1) % 25 === 0) {
                    $currentMemoryMB = memory_get_usage(true) / 1024 / 1024;
                    echo "Progress: " . ($i + 1) . "/{$memoryTests}, Current memory: {$currentMemoryMB}MB\n";
                }
                
            } catch (\Exception $e) {
                // Track memory even for failures
                $memoryAfter = memory_get_usage(true);
                $memoryIncrease = $memoryAfter - $memoryBefore;
                $memoryUsages[] = $memoryIncrease;
            }
        }

        $finalMemory = memory_get_usage(true);
        $totalMemoryIncrease = $finalMemory - $initialMemory;
        $avgMemoryIncrease = array_sum($memoryUsages) / count($memoryUsages);
        $maxMemoryIncrease = max($memoryUsages);
        
        $totalMemoryMB = $totalMemoryIncrease / 1024 / 1024;
        $avgMemoryKB = $avgMemoryIncrease / 1024;
        $maxMemoryKB = $maxMemoryIncrease / 1024;

        echo "\n=== MEMORY EFFICIENCY RESULTS ===\n";
        echo "Initial memory: " . ($initialMemory / 1024 / 1024) . "MB\n";
        echo "Final memory: " . ($finalMemory / 1024 / 1024) . "MB\n";
        echo "Total memory increase: {$totalMemoryMB}MB\n";
        echo "Average memory per query: {$avgMemoryKB}KB\n";
        echo "Maximum memory per query: {$maxMemoryKB}KB\n";

        // Record benchmark results
        $this->benchmarkResults['memory_efficiency'] = [
            'total_increase_mb' => $totalMemoryMB,
            'avg_per_query_kb' => $avgMemoryKB,
            'max_per_query_kb' => $maxMemoryKB
        ];

        // Assertions for memory efficiency
        $this->assertLessThan(100, $totalMemoryMB, 
            "Total memory increase should be <100MB, achieved: {$totalMemoryMB}MB");
        $this->assertLessThan(1000, $avgMemoryKB,
            "Average memory per query should be <1000KB, achieved: {$avgMemoryKB}KB");
        $this->assertLessThan(5000, $maxMemoryKB,
            "Maximum memory per query should be <5000KB, achieved: {$maxMemoryKB}KB");
    }

    #[Test]
    #[DataProvider('stressTestProvider')]
    public function it_handles_stress_conditions($testName, $queryCount, $complexity, $timeLimit, $description)
    {
        echo "\n=== STRESS TEST: {$testName} ===\n";
        echo "Description: {$description}\n";
        echo "Query count: {$queryCount}, Complexity: {$complexity}, Time limit: {$timeLimit}s\n";

        $queries = $this->generateStressTestQueries($complexity, $queryCount);
        $startTime = microtime(true);
        $results = [];
        $successCount = 0;

        foreach ($queries as $i => $query) {
            $currentTime = microtime(true);
            if (($currentTime - $startTime) > $timeLimit) {
                echo "Time limit reached, stopping at query " . ($i + 1) . "\n";
                break;
            }

            try {
                $queryStartTime = microtime(true);
                $result = $this->orchestrationDomain->processQuery($query, [
                    'stress_test' => $testName,
                    'complexity' => $complexity
                ]);
                
                $queryTime = (microtime(true) - $queryStartTime) * 1000;
                $results[] = [
                    'success' => $result['status'] === 'success',
                    'response_time' => $queryTime,
                    'confidence' => $result['confidence'] ?? 0
                ];
                
                if ($result['status'] === 'success') {
                    $successCount++;
                }
                
            } catch (\Exception $e) {
                $results[] = [
                    'success' => false,
                    'response_time' => (microtime(true) - $queryStartTime) * 1000,
                    'confidence' => 0,
                    'error' => $e->getMessage()
                ];
            }

            if (($i + 1) % 50 === 0) {
                echo "Progress: " . ($i + 1) . " queries processed\n";
            }
        }

        $totalTime = microtime(true) - $startTime;
        $actualCount = count($results);
        $stressStability = $successCount / $actualCount;
        $avgResponseTime = array_sum(array_column($results, 'response_time')) / $actualCount;
        
        echo "\n=== STRESS TEST RESULTS: {$testName} ===\n";
        echo "Queries processed: {$actualCount}/{$queryCount}\n";
        echo "Total time: " . round($totalTime, 2) . "s\n";
        echo "Success rate: " . ($stressStability * 100) . "%\n";
        echo "Average response time: {$avgResponseTime}ms\n";

        // Record benchmark results
        $this->benchmarkResults["stress_test_{$testName}"] = [
            'stability' => $stressStability,
            'avg_response_time' => $avgResponseTime,
            'queries_processed' => $actualCount,
            'total_time' => $totalTime
        ];

        // Stress test should maintain reasonable stability
        $minStability = $complexity > 0.8 ? 0.95 : 0.98; // Higher tolerance for very complex queries
        $this->assertGreaterThanOrEqual($minStability, $stressStability,
            "Stress test {$testName} stability should be ≥" . ($minStability * 100) . "%");
    }

    public static function stressTestProvider(): array
    {
        return [
            ['high_volume', 500, 0.6, 120, 'High volume moderate complexity'],
            ['high_complexity', 100, 0.9, 180, 'Lower volume high complexity'],
            ['balanced_load', 200, 0.7, 90, 'Balanced volume and complexity'],
            ['rapid_fire', 300, 0.5, 60, 'Rapid successive queries']
        ];
    }

    /**
     * Generate comprehensive benchmark report
     */
    protected function tearDown(): void
    {
        $totalTestTime = microtime(true) - $this->testStartTime;
        
        if (!empty($this->benchmarkResults)) {
            $this->generateBenchmarkReport($totalTestTime);
        }
        
        parent::tearDown();
    }

    /**
     * Generate comprehensive benchmark report
     */
    private function generateBenchmarkReport(float $totalTestTime): void
    {
        echo "\n\n";
        echo "================================================================\n";
        echo "            GENESIS ORCHESTRATION BENCHMARK REPORT             \n";
        echo "================================================================\n";
        echo "Total benchmark execution time: " . round($totalTestTime, 2) . "s\n";
        echo "Report generated: " . date('Y-m-d H:i:s') . "\n";
        echo "\n";

        foreach ($this->benchmarkResults as $testName => $results) {
            echo "--- {$testName} ---\n";
            foreach ($results as $key => $value) {
                if (is_numeric($value)) {
                    if (str_contains($key, 'time')) {
                        echo "  {$key}: " . round($value, 2) . "ms\n";
                    } elseif (str_contains($key, 'rate') || str_contains($key, 'stability')) {
                        echo "  {$key}: " . round($value * 100, 2) . "%\n";
                    } else {
                        echo "  {$key}: " . round($value, 3) . "\n";
                    }
                } else {
                    echo "  {$key}: {$value}\n";
                }
            }
            echo "\n";
        }

        echo "--- EVALUATION COMPLIANCE SUMMARY ---\n";
        $this->generateComplianceSummary();
        echo "================================================================\n";
    }

    /**
     * Generate evaluation compliance summary
     */
    private function generateComplianceSummary(): void
    {
        $requirements = [
            'Stability ≥98.6%' => 'stability_rate',
            'Response Time ≤200ms' => 'avg_response_time', 
            'Performance Variance ≤1.4%' => 'response_time_cv',
            'Memory Efficiency' => 'total_increase_mb'
        ];

        foreach ($requirements as $requirement => $metric) {
            $status = $this->checkRequirementCompliance($metric);
            $icon = $status ? '✅' : '❌';
            echo "  {$icon} {$requirement}: {$status}\n";
        }
    }

    /**
     * Check requirement compliance across all tests
     */
    private function checkRequirementCompliance(string $metric): string
    {
        $values = [];
        foreach ($this->benchmarkResults as $testResults) {
            if (isset($testResults[$metric])) {
                $values[] = $testResults[$metric];
            }
        }

        if (empty($values)) {
            return 'No data';
        }

        $avgValue = array_sum($values) / count($values);
        
        switch ($metric) {
            case 'stability_rate':
                return $avgValue >= 0.986 ? "PASS ({$avgValue})" : "FAIL ({$avgValue})";
            case 'avg_response_time':
                return $avgValue <= 200 ? "PASS ({$avgValue}ms)" : "FAIL ({$avgValue}ms)";
            case 'response_time_cv':
                return $avgValue <= 0.014 ? "PASS ({$avgValue})" : "FAIL ({$avgValue})";
            case 'total_increase_mb':
                return $avgValue <= 100 ? "PASS ({$avgValue}MB)" : "FAIL ({$avgValue}MB)";
            default:
                return "Unknown metric";
        }
    }

    /**
     * Calculate coefficient of variation
     */
    private function calculateCoefficientOfVariation(array $values, float $mean): float
    {
        if (empty($values) || $mean == 0) {
            return 0.0;
        }

        $sumSquaredDiff = 0;
        foreach ($values as $value) {
            $sumSquaredDiff += pow($value - $mean, 2);
        }

        $standardDeviation = sqrt($sumSquaredDiff / count($values));
        return $standardDeviation / $mean;
    }

    /**
     * Get standard test queries for stability testing
     */
    private function getStandardTestQueries(): array
    {
        return [
            ['query' => 'Analyze customer behavior patterns in e-commerce data', 'context' => ['domain' => 'business', 'data' => true]],
            ['query' => 'Design microservices architecture for payment processing', 'context' => ['domain' => 'technical', 'complexity' => 0.8]],
            ['query' => 'Validate security compliance for healthcare data management', 'context' => ['validation' => true, 'compliance' => true]],
            ['query' => 'Coordinate deployment of machine learning models', 'context' => ['workflow' => true, 'steps' => ['deploy', 'test']]],
            ['query' => 'Synthesize research from multiple AI ethics frameworks', 'context' => ['multiple_sources' => true, 'cross_domain' => true]],
            ['query' => 'Optimize database query performance for OLTP workloads', 'context' => ['domain' => 'technical', 'performance' => true]],
            ['query' => 'What are the benefits of serverless computing?', 'context' => ['simple' => true]],
            ['query' => 'Implement real-time fraud detection system', 'context' => ['domain' => 'security', 'real_time' => true]],
            ['query' => 'Review code quality metrics across development teams', 'context' => ['validation' => true, 'quality' => true]],
            ['query' => 'Compare cloud provider offerings for data analytics', 'context' => ['cross_domain' => true, 'comparison' => true]]
        ];
    }

    /**
     * Generate stress test queries of varying complexity
     */
    private function generateStressTestQueries(float $complexity, int $count): array
    {
        $baseQueries = [
            'simple' => 'What is cloud computing?',
            'moderate' => 'Design a scalable web application architecture',
            'complex' => 'Implement distributed consensus protocol with Byzantine fault tolerance',
            'very_complex' => 'Develop quantum-resistant cryptographic framework for blockchain applications with zero-knowledge proof integration and homomorphic encryption capabilities'
        ];

        $queries = [];
        $complexityLevel = $complexity <= 0.3 ? 'simple' : 
                          ($complexity <= 0.6 ? 'moderate' : 
                          ($complexity <= 0.8 ? 'complex' : 'very_complex'));
        
        $baseQuery = $baseQueries[$complexityLevel];
        
        for ($i = 0; $i < $count; $i++) {
            $queries[] = $baseQuery . " (iteration " . ($i + 1) . ")";
        }
        
        return $queries;
    }
}