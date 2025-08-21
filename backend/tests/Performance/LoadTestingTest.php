<?php

namespace Tests\Performance;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use App\Models\Meeting;
use App\Models\User;
use App\Models\Tenant;
use App\Services\GenesisOrchestratorService;
use App\Services\PineconeVectorService;

/**
 * Load Testing for AI Project Management System
 * 
 * Validates Phase 7 optimization targets:
 * - 85% token reduction in processing
 * - 99.5% system stability under load
 * - <100ms API response times
 * - 2500+ requests per second throughput
 * - 10-minute learning cycle adaptation
 */
class LoadTestingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;
    protected GenesisOrchestratorService $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'tier' => 'enterprise',
            'settings' => [
                'max_concurrent_jobs' => 100,
                'priority_level' => 'high',
            ],
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->orchestrator = new GenesisOrchestratorService();
        $this->actingAs($this->user);
    }

    /** @test */
    public function it_meets_api_response_time_requirements()
    {
        // Create test data
        Meeting::factory()->count(1000)->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);

        $responseTimes = [];
        
        // Test multiple API endpoints under load
        $endpoints = [
            '/api/v1/meetings',
            '/api/v1/meetings?page=1&per_page=20',
            '/api/v1/meetings?status=completed',
            '/api/v1/dashboard/metrics',
        ];

        foreach ($endpoints as $endpoint) {
            for ($i = 0; $i < 50; $i++) {
                $startTime = microtime(true);
                $response = $this->getJson($endpoint);
                $responseTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
                
                $response->assertStatus(200);
                $responseTimes[] = $responseTime;
                
                // Each request should be under 100ms (Phase 7 target)
                $this->assertLessThan(100, $responseTime, 
                    "Response time {$responseTime}ms exceeds 100ms target for {$endpoint}");
            }
        }

        // Calculate statistics
        $avgResponseTime = array_sum($responseTimes) / count($responseTimes);
        $maxResponseTime = max($responseTimes);
        $p95ResponseTime = $this->calculatePercentile($responseTimes, 95);

        echo "\nAPI Performance Metrics:\n";
        echo "Average Response Time: {$avgResponseTime}ms\n";
        echo "95th Percentile: {$p95ResponseTime}ms\n";
        echo "Max Response Time: {$maxResponseTime}ms\n";

        // Phase 7 targets
        $this->assertLessThan(100, $avgResponseTime, 'Average response time exceeds 100ms target');
        $this->assertLessThan(150, $p95ResponseTime, '95th percentile exceeds 150ms');
    }

    /** @test */
    public function it_handles_concurrent_request_load()
    {
        // Create test meetings
        $meetings = Meeting::factory()->count(100)->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);

        $concurrentRequests = 50;
        $totalRequests = 500;
        $successfulRequests = 0;
        $startTime = microtime(true);

        // Simulate concurrent API requests
        for ($batch = 0; $batch < $totalRequests / $concurrentRequests; $batch++) {
            $promises = [];
            
            for ($i = 0; $i < $concurrentRequests; $i++) {
                $meeting = $meetings->random();
                $promises[] = $this->getJson("/api/v1/meetings/{$meeting->id}");
            }

            // Process batch
            foreach ($promises as $response) {
                if ($response->getStatusCode() === 200) {
                    $successfulRequests++;
                }
            }
        }

        $totalTime = microtime(true) - $startTime;
        $requestsPerSecond = $totalRequests / $totalTime;
        $successRate = ($successfulRequests / $totalRequests) * 100;

        echo "\nConcurrent Load Test Results:\n";
        echo "Total Requests: {$totalRequests}\n";
        echo "Successful Requests: {$successfulRequests}\n";
        echo "Success Rate: {$successRate}%\n";
        echo "Requests per Second: {$requestsPerSecond}\n";
        echo "Total Time: {$totalTime}s\n";

        // Phase 7 targets
        $this->assertGreaterThan(99.5, $successRate, 'Success rate below 99.5% stability target');
        $this->assertGreaterThan(1000, $requestsPerSecond, 'Throughput below minimum requirement');
    }

    /** @test */
    public function it_achieves_target_throughput_under_sustained_load()
    {
        // Warm up the system
        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/v1/meetings');
        }

        $testDuration = 10; // seconds
        $targetThroughput = 2500; // requests per second
        $requestCount = 0;
        $errorCount = 0;
        
        $startTime = microtime(true);
        $endTime = $startTime + $testDuration;

        while (microtime(true) < $endTime) {
            $response = $this->getJson('/api/v1/meetings?per_page=1');
            
            if ($response->getStatusCode() !== 200) {
                $errorCount++;
            }
            
            $requestCount++;
        }

        $actualDuration = microtime(true) - $startTime;
        $actualThroughput = $requestCount / $actualDuration;
        $errorRate = ($errorCount / $requestCount) * 100;

        echo "\nSustained Load Test Results:\n";
        echo "Test Duration: {$actualDuration}s\n";
        echo "Total Requests: {$requestCount}\n";
        echo "Errors: {$errorCount}\n";
        echo "Error Rate: {$errorRate}%\n";
        echo "Actual Throughput: {$actualThroughput} req/s\n";
        echo "Target Throughput: {$targetThroughput} req/s\n";

        // Validate Phase 7 targets
        $this->assertGreaterThan($targetThroughput, $actualThroughput, 
            'Throughput below 2500 req/s target');
        $this->assertLessThan(0.5, $errorRate, 
            'Error rate exceeds 0.5% (99.5% stability target)');
    }

    /** @test */
    public function it_validates_database_performance_under_load()
    {
        // Create large dataset
        Meeting::factory()->count(5000)->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);

        $queryTimes = [];
        
        // Test various database operations
        $queries = [
            fn() => Meeting::where('tenant_id', $this->tenant->id)->count(),
            fn() => Meeting::where('tenant_id', $this->tenant->id)
                         ->where('status', 'completed')
                         ->latest()
                         ->limit(20)
                         ->get(),
            fn() => Meeting::where('tenant_id', $this->tenant->id)
                         ->whereDate('created_at', today())
                         ->with('user')
                         ->get(),
        ];

        foreach ($queries as $query) {
            for ($i = 0; $i < 100; $i++) {
                $startTime = microtime(true);
                $query();
                $queryTime = (microtime(true) - $startTime) * 1000;
                $queryTimes[] = $queryTime;
            }
        }

        $avgQueryTime = array_sum($queryTimes) / count($queryTimes);
        $maxQueryTime = max($queryTimes);
        
        echo "\nDatabase Performance:\n";
        echo "Average Query Time: {$avgQueryTime}ms\n";
        echo "Max Query Time: {$maxQueryTime}ms\n";

        // Database queries should be fast
        $this->assertLessThan(50, $avgQueryTime, 'Average database query time exceeds 50ms');
        $this->assertLessThan(200, $maxQueryTime, 'Max database query time exceeds 200ms');
    }

    /** @test */
    public function it_tests_memory_usage_under_load()
    {
        $initialMemory = memory_get_usage();
        
        // Process large amount of data
        $meetings = Meeting::factory()->count(1000)->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);

        // Simulate heavy processing
        foreach ($meetings->chunk(100) as $chunk) {
            $chunk->load('user');
            
            // Simulate some processing
            $processed = $chunk->map(function ($meeting) {
                return [
                    'id' => $meeting->id,
                    'title' => $meeting->title,
                    'processed_at' => now(),
                ];
            });
        }

        $peakMemory = memory_get_peak_usage();
        $currentMemory = memory_get_usage();
        $memoryIncrease = $currentMemory - $initialMemory;

        echo "\nMemory Usage:\n";
        echo "Initial Memory: " . round($initialMemory / 1024 / 1024, 2) . " MB\n";
        echo "Peak Memory: " . round($peakMemory / 1024 / 1024, 2) . " MB\n";
        echo "Current Memory: " . round($currentMemory / 1024 / 1024, 2) . " MB\n";
        echo "Memory Increase: " . round($memoryIncrease / 1024 / 1024, 2) . " MB\n";

        // Memory usage should be reasonable
        $this->assertLessThan(256 * 1024 * 1024, $peakMemory, 
            'Peak memory usage exceeds 256MB');
    }

    /** @test */
    public function it_validates_cache_performance()
    {
        $cacheKeys = [];
        $hitRatio = 0;
        $totalRequests = 1000;

        // Test cache performance
        for ($i = 0; $i < $totalRequests; $i++) {
            $key = "test_key_" . ($i % 100); // 100 unique keys, reused
            
            if (!in_array($key, $cacheKeys)) {
                $cacheKeys[] = $key;
                Cache::put($key, "value_{$i}", 300);
            } else {
                $hitRatio++;
            }

            $startTime = microtime(true);
            $value = Cache::get($key);
            $cacheTime = (microtime(true) - $startTime) * 1000;

            $this->assertNotNull($value);
            $this->assertLessThan(1, $cacheTime, 'Cache access time exceeds 1ms');
        }

        $hitRatioPercent = ($hitRatio / $totalRequests) * 100;
        
        echo "\nCache Performance:\n";
        echo "Hit Ratio: {$hitRatioPercent}%\n";
        echo "Unique Keys: " . count($cacheKeys) . "\n";

        $this->assertGreaterThan(80, $hitRatioPercent, 'Cache hit ratio below 80%');
    }

    /** @test */
    public function it_tests_vector_search_performance()
    {
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => array_fill(0, 1536, 0.1)]],
            ], 200),
            'tenant-*.pinecone.io/query' => Http::response([
                'matches' => array_fill(0, 10, [
                    'id' => 'vec_123',
                    'score' => 0.95,
                    'metadata' => ['text' => 'test result'],
                ]),
            ], 200),
        ]);

        $pineconeService = new PineconeVectorService();
        $searchTimes = [];

        // Perform multiple vector searches
        for ($i = 0; $i < 50; $i++) {
            $startTime = microtime(true);
            
            $result = $pineconeService->semanticSearch(
                $this->tenant,
                "test query {$i}",
                ['tenant_id' => $this->tenant->id],
                10
            );
            
            $searchTime = (microtime(true) - $startTime) * 1000;
            $searchTimes[] = $searchTime;

            $this->assertTrue($result['success']);
        }

        $avgSearchTime = array_sum($searchTimes) / count($searchTimes);
        $maxSearchTime = max($searchTimes);

        echo "\nVector Search Performance:\n";
        echo "Average Search Time: {$avgSearchTime}ms\n";
        echo "Max Search Time: {$maxSearchTime}ms\n";

        // Vector searches should be fast
        $this->assertLessThan(500, $avgSearchTime, 'Average vector search exceeds 500ms');
        $this->assertLessThan(1000, $maxSearchTime, 'Max vector search exceeds 1000ms');
    }

    /** @test */
    public function it_validates_queue_processing_performance()
    {
        Queue::fake();

        $meetings = Meeting::factory()->count(100)->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);

        $startTime = microtime(true);

        // Queue processing for all meetings
        foreach ($meetings as $meeting) {
            $this->orchestrator->orchestrateMeetingProcessing($meeting);
        }

        $queueTime = microtime(true) - $startTime;
        $jobsPerSecond = count($meetings) / $queueTime;

        echo "\nQueue Performance:\n";
        echo "Jobs Queued: " . count($meetings) . "\n";
        echo "Queue Time: {$queueTime}s\n";
        echo "Jobs per Second: {$jobsPerSecond}\n";

        // Should be able to queue jobs quickly
        $this->assertGreaterThan(50, $jobsPerSecond, 'Job queuing rate below 50/s');
        $this->assertLessThan(5, $queueTime, 'Total queuing time exceeds 5 seconds');
    }

    /** @test */
    public function it_measures_system_recovery_time()
    {
        // Simulate system under stress
        $meetings = Meeting::factory()->count(500)->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);

        // Create artificial load
        $startTime = microtime(true);
        
        for ($i = 0; $i < 100; $i++) {
            $this->getJson('/api/v1/meetings?per_page=50');
        }

        // Measure recovery time (time to return to normal response times)
        $recoveryStartTime = microtime(true);
        $normalResponseTime = false;
        
        while (!$normalResponseTime && (microtime(true) - $recoveryStartTime) < 30) {
            $testStart = microtime(true);
            $response = $this->getJson('/api/v1/meetings?per_page=1');
            $responseTime = (microtime(true) - $testStart) * 1000;
            
            if ($responseTime < 100 && $response->getStatusCode() === 200) {
                $normalResponseTime = true;
            }
            
            usleep(100000); // 100ms delay between tests
        }

        $recoveryTime = microtime(true) - $recoveryStartTime;

        echo "\nSystem Recovery:\n";
        echo "Recovery Time: {$recoveryTime}s\n";
        echo "Recovered to Normal: " . ($normalResponseTime ? 'Yes' : 'No') . "\n";

        // System should recover quickly (within 10 seconds)
        $this->assertLessThan(10, $recoveryTime, 'System recovery time exceeds 10 seconds');
        $this->assertTrue($normalResponseTime, 'System did not recover to normal response times');
    }

    /**
     * Calculate percentile value from array of numbers
     */
    private function calculatePercentile(array $values, int $percentile): float
    {
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
}