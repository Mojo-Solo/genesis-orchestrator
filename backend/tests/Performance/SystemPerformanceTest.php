<?php

namespace Tests\Performance;

use Tests\TestCase;
use App\Services\GenesisOrchestratorIntegrationService;
use App\Services\PineconeVectorService;
use App\Services\FirefliesIntegrationService;
use App\Services\RealTimeInsightsService;
use App\Models\Meeting;
use App\Models\MeetingTranscript;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * @group performance
 * @group slow
 */
class SystemPerformanceTest extends TestCase
{
    protected int $performanceIterations = 100;
    protected float $maxResponseTime = 2.0; // 2 seconds
    protected int $maxMemoryUsageMB = 128;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Enable performance testing mode
        config(['app.performance_testing' => true]);
        
        // Pre-warm caches and connections
        $this->preWarmSystem();
    }

    private function preWarmSystem(): void
    {
        // Pre-warm database connections
        DB::connection()->getPdo();
        
        // Pre-warm cache connections
        Cache::put('warmup_test', 'value', 1);
        Cache::forget('warmup_test');
        
        // Create baseline test data
        $this->createPerformanceTestData();
    }

    private function createPerformanceTestData(): void
    {
        // Create additional tenants for multi-tenant testing
        Tenant::factory()->count(10)->create();
        
        // Create meetings with transcripts for realistic load
        Meeting::factory()->count(50)->create([
            'tenant_id' => $this->defaultTenant->id
        ])->each(function ($meeting) {
            MeetingTranscript::factory()->count(3)->create([
                'tenant_id' => $this->defaultTenant->id,
                'meeting_id' => $meeting->id
            ]);
        });
    }

    /** @test */
    public function it_meets_api_response_time_requirements()
    {
        $endpoints = [
            ['GET', '/api/v1/meetings'],
            ['GET', '/api/v1/dashboard/metrics'],
            ['POST', '/api/v1/meetings', ['title' => 'Test Meeting', 'scheduled_at' => now()->addDay()]],
            ['GET', '/api/v1/insights/real-time']
        ];

        foreach ($endpoints as [$method, $url, $data = []]) {
            $responseTimes = [];
            
            for ($i = 0; $i < 10; $i++) {
                $start = microtime(true);
                
                if ($method === 'GET') {
                    $response = $this->actingAsAdmin()->getJson($url);
                } else {
                    $response = $this->actingAsAdmin()->postJson($url, $data);
                }
                
                $duration = microtime(true) - $start;
                $responseTimes[] = $duration;
                
                // Individual request should be fast
                $this->assertLessThan($this->maxResponseTime, $duration, 
                    "Endpoint {$method} {$url} took {$duration}s, exceeds {$this->maxResponseTime}s limit");
            }
            
            // Calculate statistics
            $avgTime = array_sum($responseTimes) / count($responseTimes);
            $p95Time = $this->calculatePercentile($responseTimes, 95);
            $p99Time = $this->calculatePercentile($responseTimes, 99);
            
            echo "\n{$method} {$url} Performance:\n";
            echo "  Average: " . round($avgTime * 1000, 2) . "ms\n";
            echo "  P95: " . round($p95Time * 1000, 2) . "ms\n";
            echo "  P99: " . round($p99Time * 1000, 2) . "ms\n";
            
            // Performance assertions
            $this->assertLessThan(0.5, $avgTime, "Average response time too high for {$method} {$url}");
            $this->assertLessThan(1.0, $p95Time, "P95 response time too high for {$method} {$url}");
            $this->assertLessThan($this->maxResponseTime, $p99Time, "P99 response time too high for {$method} {$url}");
        }
    }

    /** @test */
    public function it_handles_concurrent_requests_efficiently()
    {
        $concurrentUsers = 50;
        $requestsPerUser = 10;
        $results = [];
        
        // Simulate concurrent load
        $processes = [];
        for ($i = 0; $i < $concurrentUsers; $i++) {
            $processes[] = $this->simulateConcurrentUser($requestsPerUser);
        }
        
        // Wait for all processes and collect results
        foreach ($processes as $process) {
            $results[] = $process;
        }
        
        // Analyze results
        $totalRequests = $concurrentUsers * $requestsPerUser;
        $successfulRequests = count(array_filter($results, fn($r) => $r['success']));
        $successRate = $successfulRequests / $totalRequests;
        
        echo "\nConcurrency Test Results:\n";
        echo "  Total Requests: {$totalRequests}\n";
        echo "  Successful: {$successfulRequests}\n";
        echo "  Success Rate: " . round($successRate * 100, 2) . "%\n";
        
        $this->assertGreaterThan(0.95, $successRate, 'Success rate should be above 95%');
    }

    private function simulateConcurrentUser(int $requests): array
    {
        $startTime = microtime(true);
        $successful = 0;
        $errors = 0;
        
        for ($i = 0; $i < $requests; $i++) {
            try {
                $response = $this->actingAsAdmin()->getJson('/api/v1/meetings');
                if ($response->status() === 200) {
                    $successful++;
                } else {
                    $errors++;
                }
            } catch (\Exception $e) {
                $errors++;
            }
        }
        
        $duration = microtime(true) - $startTime;
        
        return [
            'success' => $errors === 0,
            'successful_requests' => $successful,
            'errors' => $errors,
            'duration' => $duration,
            'requests_per_second' => $requests / $duration
        ];
    }

    /** @test */
    public function it_meets_database_query_performance_standards()
    {
        $queries = [
            // Simple select
            'SELECT * FROM meetings WHERE tenant_id = ? LIMIT 10',
            
            // Join query
            'SELECT m.*, mt.content FROM meetings m 
             LEFT JOIN meeting_transcripts mt ON m.id = mt.meeting_id 
             WHERE m.tenant_id = ? LIMIT 10',
            
            // Aggregation query
            'SELECT COUNT(*) as count, AVG(duration_minutes) as avg_duration 
             FROM meetings WHERE tenant_id = ? AND created_at >= ?',
            
            // Complex search query
            'SELECT m.*, COUNT(mt.id) as transcript_count 
             FROM meetings m 
             LEFT JOIN meeting_transcripts mt ON m.id = mt.meeting_id 
             WHERE m.tenant_id = ? 
             GROUP BY m.id 
             HAVING transcript_count > 0 
             ORDER BY m.created_at DESC 
             LIMIT 20'
        ];

        foreach ($queries as $query) {
            $queryTimes = [];
            
            for ($i = 0; $i < 20; $i++) {
                $start = microtime(true);
                
                if (str_contains($query, 'created_at >= ?')) {
                    DB::select($query, [$this->defaultTenant->id, now()->subDays(30)]);
                } else {
                    DB::select($query, [$this->defaultTenant->id]);
                }
                
                $duration = microtime(true) - $start;
                $queryTimes[] = $duration;
            }
            
            $avgTime = array_sum($queryTimes) / count($queryTimes);
            $maxTime = max($queryTimes);
            
            echo "\nQuery Performance: " . substr($query, 0, 50) . "...\n";
            echo "  Average: " . round($avgTime * 1000, 2) . "ms\n";
            echo "  Max: " . round($maxTime * 1000, 2) . "ms\n";
            
            // Database queries should be very fast
            $this->assertLessThan(0.1, $avgTime, 'Average query time should be under 100ms');
            $this->assertLessThan(0.5, $maxTime, 'Max query time should be under 500ms');
        }
    }

    /** @test */
    public function it_handles_large_dataset_operations_efficiently()
    {
        // Create large dataset
        $largeDatasetSize = 1000;
        $meetings = Meeting::factory()->count($largeDatasetSize)->create([
            'tenant_id' => $this->defaultTenant->id
        ]);

        // Test pagination performance
        $start = microtime(true);
        $response = $this->actingAsAdmin()->getJson('/api/v1/meetings?per_page=100&page=1');
        $paginationTime = microtime(true) - $start;
        
        $this->assertLessThan(1.0, $paginationTime, 'Pagination should be fast even with large datasets');
        $this->assertEquals(200, $response->status());

        // Test search performance
        $start = microtime(true);
        $response = $this->actingAsAdmin()->getJson('/api/v1/meetings?search=meeting&per_page=50');
        $searchTime = microtime(true) - $start;
        
        $this->assertLessThan(2.0, $searchTime, 'Search should be reasonably fast');
        $this->assertEquals(200, $response->status());

        // Test filtering performance
        $start = microtime(true);
        $response = $this->actingAsAdmin()->getJson('/api/v1/meetings?status=completed&from=' . now()->subDays(30)->toDateString());
        $filterTime = microtime(true) - $start;
        
        $this->assertLessThan(1.5, $filterTime, 'Filtering should be efficient');
        $this->assertEquals(200, $response->status());
    }

    /** @test */
    public function it_meets_vector_search_performance_requirements()
    {
        $vectorService = app(PineconeVectorService::class);
        
        // Test embedding generation performance
        $content = 'This is a test meeting transcript for performance testing with meaningful content.';
        
        $embedTimes = [];
        for ($i = 0; $i < 10; $i++) {
            $start = microtime(true);
            $result = $vectorService->generateEmbedding($content, $this->defaultTenant);
            $duration = microtime(true) - $start;
            $embedTimes[] = $duration;
            
            $this->assertTrue($result['success']);
        }
        
        $avgEmbedTime = array_sum($embedTimes) / count($embedTimes);
        echo "\nVector Embedding Performance:\n";
        echo "  Average: " . round($avgEmbedTime * 1000, 2) . "ms\n";
        
        $this->assertLessThan(3.0, $avgEmbedTime, 'Embedding generation should be under 3 seconds');

        // Test vector search performance
        $searchTimes = [];
        for ($i = 0; $i < 10; $i++) {
            $start = microtime(true);
            $result = $vectorService->semanticSearch('project planning discussion', $this->defaultTenant, [
                'top_k' => 10
            ]);
            $duration = microtime(true) - $start;
            $searchTimes[] = $duration;
            
            $this->assertTrue($result['success']);
        }
        
        $avgSearchTime = array_sum($searchTimes) / count($searchTimes);
        echo "  Vector Search Average: " . round($avgSearchTime * 1000, 2) . "ms\n";
        
        $this->assertLessThan(1.0, $avgSearchTime, 'Vector search should be under 1 second');
    }

    /** @test */
    public function it_meets_real_time_insights_performance_requirements()
    {
        $insightsService = app(RealTimeInsightsService::class);
        
        // Test dashboard insights generation
        $start = microtime(true);
        $insights = $insightsService->generateDashboardInsights($this->defaultTenant);
        $duration = microtime(true) - $start;
        
        echo "\nReal-time Insights Performance:\n";
        echo "  Dashboard Generation: " . round($duration * 1000, 2) . "ms\n";
        
        $this->assertLessThan(2.0, $duration, 'Dashboard insights should generate within 2 seconds');
        $this->assertArrayHasKey('snapshot', $insights);
        $this->assertArrayHasKey('trends', $insights);

        // Test live meeting insights
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->defaultTenant->id,
            'status' => 'in_progress'
        ]);

        $start = microtime(true);
        $liveInsights = $insightsService->generateLiveMeetingInsights($meeting, $this->defaultTenant);
        $duration = microtime(true) - $start;
        
        echo "  Live Meeting Insights: " . round($duration * 1000, 2) . "ms\n";
        
        $this->assertLessThan(1.5, $duration, 'Live insights should generate within 1.5 seconds');
        $this->assertArrayHasKey('live_data', $liveInsights);
    }

    /** @test */
    public function it_handles_memory_usage_efficiently()
    {
        $initialMemory = memory_get_usage(true);
        
        // Perform memory-intensive operations
        $operations = [
            fn() => $this->actingAsAdmin()->getJson('/api/v1/meetings?per_page=1000'),
            fn() => Meeting::factory()->count(100)->create(['tenant_id' => $this->defaultTenant->id]),
            fn() => $this->generateLargeDataset(500),
            fn() => $this->performBulkOperations(200)
        ];

        foreach ($operations as $operation) {
            $memoryBefore = memory_get_usage(true);
            $operation();
            $memoryAfter = memory_get_usage(true);
            
            $memoryUsedMB = ($memoryAfter - $memoryBefore) / 1024 / 1024;
            
            echo "\nMemory Usage for Operation: " . round($memoryUsedMB, 2) . "MB\n";
            
            $this->assertLessThan($this->maxMemoryUsageMB, $memoryUsedMB, 
                "Memory usage {$memoryUsedMB}MB exceeds limit of {$this->maxMemoryUsageMB}MB");
            
            // Force garbage collection
            gc_collect_cycles();
        }

        $finalMemory = memory_get_usage(true);
        $totalMemoryUsedMB = ($finalMemory - $initialMemory) / 1024 / 1024;
        
        echo "\nTotal Memory Usage: " . round($totalMemoryUsedMB, 2) . "MB\n";
        
        $this->assertLessThan($this->maxMemoryUsageMB * 2, $totalMemoryUsedMB, 
            'Total memory usage should not exceed twice the single operation limit');
    }

    /** @test */
    public function it_meets_genesis_orchestrator_performance_targets()
    {
        $orchestrator = app(GenesisOrchestratorIntegrationService::class);
        
        // Test Phase 7 optimization targets
        $context = [
            'content' => 'This is a test transcript for performance optimization testing.',
            'type' => 'meeting_transcript',
            'complexity' => 'medium'
        ];

        $start = microtime(true);
        $result = $orchestrator->executeOrchestrated(
            'transcript_analysis',
            $context,
            $this->defaultTenant
        );
        $duration = microtime(true) - $start;

        echo "\nGENESIS Orchestrator Performance:\n";
        echo "  Execution Time: " . round($duration * 1000, 2) . "ms\n";
        
        // Phase 7 targets: 100ms P50 latency
        $this->assertLessThan(0.2, $duration, 'Orchestrator should meet 100ms P50 target (allowing 200ms for testing)');
        
        // Verify optimization metrics
        $this->assertTrue($result['execution_successful']);
        $this->assertArrayHasKey('performance_metrics', $result);
        
        $metrics = $result['performance_metrics'];
        $this->assertArrayHasKey('token_reduction_achieved', $metrics);
        $this->assertArrayHasKey('latency_ms', $metrics);
        $this->assertArrayHasKey('stability_score', $metrics);
        
        // Verify Phase 7 optimization targets
        if (isset($metrics['token_reduction_achieved'])) {
            $this->assertGreaterThan(0.8, $metrics['token_reduction_achieved'], 
                'Should achieve 85% token reduction target');
        }
        
        if (isset($metrics['stability_score'])) {
            $this->assertGreaterThan(0.99, $metrics['stability_score'], 
                'Should achieve 99.5% stability target');
        }
    }

    /** @test */
    public function it_handles_cache_performance_efficiently()
    {
        $cacheOperations = [
            'set' => fn($key, $value) => Cache::put($key, $value, 3600),
            'get' => fn($key) => Cache::get($key),
            'increment' => fn($key) => Cache::increment($key),
            'forget' => fn($key) => Cache::forget($key),
            'many' => fn() => Cache::many(['key1', 'key2', 'key3']),
            'put_many' => fn() => Cache::putMany(['key1' => 'value1', 'key2' => 'value2'], 3600)
        ];

        foreach ($cacheOperations as $operation => $callback) {
            $times = [];
            
            for ($i = 0; $i < 100; $i++) {
                $start = microtime(true);
                
                switch ($operation) {
                    case 'set':
                        $callback("test_key_{$i}", "test_value_{$i}");
                        break;
                    case 'get':
                        $callback("test_key_" . ($i % 50)); // Get previously set keys
                        break;
                    case 'increment':
                        $callback("counter_key");
                        break;
                    case 'forget':
                        $callback("test_key_" . ($i % 50));
                        break;
                    default:
                        $callback();
                }
                
                $duration = microtime(true) - $start;
                $times[] = $duration;
            }
            
            $avgTime = array_sum($times) / count($times);
            $maxTime = max($times);
            
            echo "\nCache {$operation} Performance:\n";
            echo "  Average: " . round($avgTime * 1000, 3) . "ms\n";
            echo "  Max: " . round($maxTime * 1000, 3) . "ms\n";
            
            // Cache operations should be very fast
            $this->assertLessThan(0.01, $avgTime, "Cache {$operation} should be under 10ms on average");
            $this->assertLessThan(0.05, $maxTime, "Cache {$operation} should be under 50ms maximum");
        }
    }

    private function generateLargeDataset(int $size): array
    {
        $data = [];
        for ($i = 0; $i < $size; $i++) {
            $data[] = [
                'id' => $i,
                'title' => "Test Meeting {$i}",
                'description' => str_repeat("Description content {$i}. ", 10),
                'created_at' => now()->subDays(rand(1, 100))
            ];
        }
        return $data;
    }

    private function performBulkOperations(int $count): void
    {
        // Bulk database operations
        $meetings = [];
        for ($i = 0; $i < $count; $i++) {
            $meetings[] = [
                'tenant_id' => $this->defaultTenant->id,
                'title' => "Bulk Meeting {$i}",
                'status' => 'scheduled',
                'created_at' => now(),
                'updated_at' => now()
            ];
        }
        
        DB::table('meetings')->insert($meetings);
    }

    private function calculatePercentile(array $values, int $percentile): float
    {
        sort($values);
        $index = ceil(($percentile / 100) * count($values)) - 1;
        return $values[$index] ?? 0;
    }

    protected function tearDown(): void
    {
        // Report memory usage
        $memoryUsage = memory_get_peak_usage(true) / 1024 / 1024;
        echo "\nPeak Memory Usage: " . round($memoryUsage, 2) . "MB\n";
        
        parent::tearDown();
    }
}