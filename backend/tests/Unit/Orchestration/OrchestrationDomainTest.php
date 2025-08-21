<?php

namespace Tests\Unit\Orchestration;

use Tests\TestCase;
use App\Domains\Orchestration\OrchestrationDomain;
use App\Domains\Orchestration\Services\LAGEngine;
use App\Domains\Orchestration\Services\RCRRouter;
use App\Domains\Orchestration\Exceptions\OrchestrationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Mockery;

/**
 * Comprehensive Orchestration Domain Test Suite
 * 
 * Tests for complete LAG+RCR orchestration pipeline ensuring ≥98.6% stability
 * and integration compliance under all operating conditions.
 * 
 * Test Coverage:
 * - End-to-end pipeline integration
 * - Circuit breaker functionality
 * - Performance metrics (≤200ms response)
 * - Stability requirements (≥98.6%)
 * - Error handling and recovery
 * - Health monitoring
 * - Caching effectiveness
 * - Concurrent processing
 */
class OrchestrationDomainTest extends TestCase
{
    private OrchestrationDomain $orchestrationDomain;
    private LAGEngine $lagEngine;
    private RCRRouter $rcrRouter;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->lagEngine = Mockery::mock(LAGEngine::class);
        $this->rcrRouter = Mockery::mock(RCRRouter::class);
        
        $this->orchestrationDomain = new OrchestrationDomain(
            $this->lagEngine,
            $this->rcrRouter
        );
    }

    #[Test]
    public function it_processes_complete_query_pipeline()
    {
        $query = "Design a scalable microservices architecture for e-commerce";
        $context = ['domain' => 'technical', 'complexity' => 0.8];

        // Mock LAG Engine response
        $this->lagEngine->shouldReceive('decompose')
            ->once()
            ->with($query, Mockery::any())
            ->andReturn([
                'decomposition' => [
                    ['query' => 'Design microservices structure', 'confidence' => 0.9],
                    ['query' => 'Plan scalability strategies', 'confidence' => 0.8],
                    ['query' => 'Implement e-commerce features', 'confidence' => 0.85]
                ],
                'confidence' => 0.85,
                'execution_plan' => ['step1', 'step2', 'step3'],
                'terminator_triggered' => false,
                'artifacts' => ['trace' => 'decomposition_trace']
            ]);

        // Mock RCR Router response
        $this->rcrRouter->shouldReceive('route')
            ->once()
            ->with($query, $context, Mockery::any())
            ->andReturn([
                'selected_role' => 'specialist',
                'confidence' => 0.9,
                'routing_rationale' => ['primary_reason' => 'Complex technical query'],
                'alternative_roles' => ['synthesizer' => ['normalized_score' => 0.7]],
                'estimated_performance' => ['estimated_response_time' => 180]
            ]);

        $result = $this->orchestrationDomain->processQuery($query, $context);

        // Validate complete result structure
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('request_id', $result);
        $this->assertArrayHasKey('lag', $result);
        $this->assertArrayHasKey('rcr', $result);
        $this->assertArrayHasKey('workflow', $result);
        $this->assertArrayHasKey('answer', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('quality_metrics', $result);
        $this->assertArrayHasKey('metadata', $result);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('specialist', $result['rcr']['selected_role']);
        $this->assertGreaterThanOrEqual(0.8, $result['confidence']);
    }

    #[Test]
    public function it_maintains_response_time_under_200ms()
    {
        $query = "Analyze system performance metrics";
        $context = ['urgency' => 'high'];

        // Mock fast responses
        $this->lagEngine->shouldReceive('decompose')
            ->andReturn([
                'decomposition' => [['query' => 'Analyze metrics', 'confidence' => 0.8]],
                'confidence' => 0.8,
                'execution_plan' => ['analyze'],
                'terminator_triggered' => false
            ]);

        $this->rcrRouter->shouldReceive('route')
            ->andReturn([
                'selected_role' => 'analyst',
                'confidence' => 0.85,
                'routing_rationale' => ['primary_reason' => 'Data analysis required'],
                'alternative_roles' => [],
                'estimated_performance' => ['estimated_response_time' => 120]
            ]);

        $processingTimes = [];

        for ($i = 0; $i < 20; $i++) {
            $startTime = microtime(true);
            $result = $this->orchestrationDomain->processQuery($query, $context);
            $processingTime = (microtime(true) - $startTime) * 1000;
            
            $processingTimes[] = $processingTime;
            $this->assertIsArray($result);
        }

        $avgTime = array_sum($processingTimes) / count($processingTimes);
        $maxTime = max($processingTimes);

        $this->assertLessThan(200, $avgTime, 'Average processing time should be under 200ms');
        $this->assertLessThan(300, $maxTime, 'Maximum processing time should be under 300ms');
    }

    #[Test]
    public function it_achieves_stability_target()
    {
        $query = "Implement data security framework";
        $context = ['domain' => 'security'];

        // Mock consistent responses
        $this->lagEngine->shouldReceive('decompose')
            ->andReturn([
                'decomposition' => [['query' => 'Security framework', 'confidence' => 0.88]],
                'confidence' => 0.88,
                'execution_plan' => ['design', 'implement'],
                'terminator_triggered' => false
            ]);

        $this->rcrRouter->shouldReceive('route')
            ->andReturn([
                'selected_role' => 'specialist',
                'confidence' => 0.87,
                'routing_rationale' => ['primary_reason' => 'Security specialization required'],
                'alternative_roles' => [],
                'estimated_performance' => ['estimated_response_time' => 150]
            ]);

        $successCount = 0;
        $totalRuns = 100;

        for ($i = 0; $i < $totalRuns; $i++) {
            try {
                $result = $this->orchestrationDomain->processQuery($query, $context);
                if ($result['status'] === 'success' && $result['confidence'] >= 0.8) {
                    $successCount++;
                }
            } catch (\Exception $e) {
                // Failures count against stability
            }
        }

        $stability = $successCount / $totalRuns;
        $this->assertGreaterThanOrEqual(0.986, $stability, 
            "Stability should be ≥98.6%, got " . ($stability * 100) . "%");
    }

    #[Test]
    public function it_handles_circuit_breaker_correctly()
    {
        $query = "Test circuit breaker functionality";
        
        // Mock failures to trigger circuit breaker
        $this->lagEngine->shouldReceive('decompose')
            ->andThrow(new \Exception('Simulated LAG failure'));

        // First few failures should be handled normally
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->orchestrationDomain->processQuery($query);
            } catch (OrchestrationException $e) {
                $this->assertStringContains('Orchestration failed', $e->getMessage());
            }
        }

        // After threshold, circuit breaker should open
        for ($i = 0; $i < 10; $i++) {
            try {
                $this->orchestrationDomain->processQuery($query);
                $this->fail('Should throw circuit breaker exception');
            } catch (OrchestrationException $e) {
                if (str_contains($e->getMessage(), 'Circuit breaker is open')) {
                    // Circuit breaker is working
                    $this->assertTrue(true);
                    break;
                }
            }
        }

        // Health status should reflect circuit breaker state
        $health = $this->orchestrationDomain->getHealthStatus();
        $this->assertContains($health['status'], ['degraded', 'failed']);
    }

    #[Test]
    public function it_provides_comprehensive_quality_metrics()
    {
        $query = "Optimize database query performance";
        $context = ['database' => true, 'performance' => true];

        $this->lagEngine->shouldReceive('decompose')
            ->andReturn([
                'decomposition' => [['query' => 'DB optimization', 'confidence' => 0.9]],
                'confidence' => 0.9,
                'execution_plan' => ['analyze', 'optimize'],
                'terminator_triggered' => false,
                'termination_reason' => 'completed'
            ]);

        $this->rcrRouter->shouldReceive('route')
            ->andReturn([
                'selected_role' => 'specialist',
                'confidence' => 0.88,
                'routing_rationale' => ['primary_reason' => 'Database expertise required'],
                'alternative_roles' => ['analyst' => ['normalized_score' => 0.7]],
                'estimated_performance' => ['estimated_response_time' => 160]
            ]);

        $result = $this->orchestrationDomain->processQuery($query, $context);

        $this->assertArrayHasKey('quality_metrics', $result);
        $qualityMetrics = $result['quality_metrics'];

        // Should include all quality dimensions
        $this->assertArrayHasKey('lag_quality', $qualityMetrics);
        $this->assertArrayHasKey('rcr_quality', $qualityMetrics);
        $this->assertArrayHasKey('workflow_quality', $qualityMetrics);
        $this->assertArrayHasKey('overall_score', $qualityMetrics);

        // LAG quality metrics
        $lagQuality = $qualityMetrics['lag_quality'];
        $this->assertArrayHasKey('decomposition_depth', $lagQuality);
        $this->assertArrayHasKey('confidence', $lagQuality);
        $this->assertArrayHasKey('termination_reason', $lagQuality);

        // RCR quality metrics
        $rcrQuality = $qualityMetrics['rcr_quality'];
        $this->assertArrayHasKey('routing_confidence', $rcrQuality);
        $this->assertArrayHasKey('role_match_score', $rcrQuality);
        $this->assertArrayHasKey('performance_estimate', $rcrQuality);

        // Overall score should be reasonable
        $this->assertGreaterThanOrEqual(0.7, $qualityMetrics['overall_score']);
        $this->assertLessThanOrEqual(1.0, $qualityMetrics['overall_score']);
    }

    #[Test]
    public function it_handles_caching_effectively()
    {
        $query = "Calculate financial projections for next quarter";
        $context = ['finance' => true, 'cacheable' => true];

        $this->lagEngine->shouldReceive('decompose')
            ->once() // Should only be called once due to caching
            ->andReturn([
                'decomposition' => [['query' => 'Financial projections', 'confidence' => 0.85]],
                'confidence' => 0.85,
                'execution_plan' => ['calculate', 'project'],
                'terminator_triggered' => false
            ]);

        $this->rcrRouter->shouldReceive('route')
            ->once() // Should only be called once due to caching
            ->andReturn([
                'selected_role' => 'analyst',
                'confidence' => 0.82,
                'routing_rationale' => ['primary_reason' => 'Financial analysis'],
                'alternative_roles' => [],
                'estimated_performance' => ['estimated_response_time' => 140]
            ]);

        // First call should process normally
        $result1 = $this->orchestrationDomain->processQuery($query, $context);
        
        // Second call with same query should use cache
        $result2 = $this->orchestrationDomain->processQuery($query, $context);

        $this->assertArrayHasKey('performance', $result2);
        // Note: In real implementation, second call would be marked as cached
        // This is a simplified test focusing on the caching logic structure
    }

    #[Test]
    public function it_handles_lag_processing_failures_gracefully()
    {
        $query = "Test LAG failure handling";
        $context = ['test' => true];

        // Mock LAG failure
        $this->lagEngine->shouldReceive('decompose')
            ->andThrow(new \Exception('LAG processing failed'));

        $this->rcrRouter->shouldReceive('route')
            ->andReturn([
                'selected_role' => 'coordinator',
                'confidence' => 0.3,
                'fallback' => true,
                'routing_rationale' => ['primary_reason' => 'Fallback routing'],
                'alternative_roles' => [],
                'estimated_performance' => ['estimated_response_time' => 100]
            ]);

        try {
            $result = $this->orchestrationDomain->processQuery($query, $context);
            $this->fail('Should throw OrchestrationException on LAG failure');
        } catch (OrchestrationException $e) {
            $this->assertStringContains('LAG processing failed', $e->getMessage());
            
            // Metrics should track the failure
            $metrics = $this->orchestrationDomain->getMetrics();
            $this->assertGreaterThan(0, $metrics['failed_requests']);
        }
    }

    #[Test]
    public function it_handles_rcr_routing_failures_gracefully()
    {
        $query = "Test RCR failure handling";
        $context = ['test' => true];

        $this->lagEngine->shouldReceive('decompose')
            ->andReturn([
                'decomposition' => [['query' => 'Test query', 'confidence' => 0.7]],
                'confidence' => 0.7,
                'execution_plan' => ['test'],
                'terminator_triggered' => false
            ]);

        // Mock RCR failure
        $this->rcrRouter->shouldReceive('route')
            ->andThrow(new \Exception('RCR routing failed'));

        try {
            $result = $this->orchestrationDomain->processQuery($query, $context);
            $this->fail('Should throw OrchestrationException on RCR failure');
        } catch (OrchestrationException $e) {
            $this->assertStringContains('Orchestration failed', $e->getMessage());
        }
    }

    #[Test]
    public function it_provides_health_status_monitoring()
    {
        // Perform some operations to establish metrics
        $query = "Health check test query";
        $context = ['health_check' => true];

        $this->lagEngine->shouldReceive('decompose')
            ->times(5)
            ->andReturn([
                'decomposition' => [['query' => 'Health check', 'confidence' => 0.9]],
                'confidence' => 0.9,
                'execution_plan' => ['check'],
                'terminator_triggered' => false
            ]);

        $this->rcrRouter->shouldReceive('route')
            ->times(5)
            ->andReturn([
                'selected_role' => 'coordinator',
                'confidence' => 0.85,
                'routing_rationale' => ['primary_reason' => 'Health check'],
                'alternative_roles' => [],
                'estimated_performance' => ['estimated_response_time' => 100]
            ]);

        // Run several successful operations
        for ($i = 0; $i < 5; $i++) {
            $this->orchestrationDomain->processQuery($query, $context);
        }

        $health = $this->orchestrationDomain->getHealthStatus();

        $this->assertArrayHasKey('status', $health);
        $this->assertArrayHasKey('performance_status', $health);
        $this->assertArrayHasKey('circuit_breaker_status', $health);
        $this->assertArrayHasKey('metrics', $health);
        $this->assertArrayHasKey('recommendations', $health);

        $this->assertContains($health['status'], ['healthy', 'degraded', 'failed']);
        $this->assertContains($health['performance_status'], ['optimal', 'good', 'acceptable', 'needs_optimization']);
        $this->assertEquals('closed', $health['circuit_breaker_status']);
        $this->assertIsArray($health['recommendations']);
    }

    #[Test]
    public function it_tracks_performance_metrics_accurately()
    {
        $query = "Metrics tracking test";
        $context = ['metrics' => true];

        $this->lagEngine->shouldReceive('decompose')
            ->times(10)
            ->andReturn([
                'decomposition' => [['query' => 'Metrics test', 'confidence' => 0.85]],
                'confidence' => 0.85,
                'execution_plan' => ['test'],
                'terminator_triggered' => false
            ]);

        $this->rcrRouter->shouldReceive('route')
            ->times(10)
            ->andReturn([
                'selected_role' => 'coordinator',
                'confidence' => 0.8,
                'routing_rationale' => ['primary_reason' => 'Metrics test'],
                'alternative_roles' => [],
                'estimated_performance' => ['estimated_response_time' => 120]
            ]);

        // Process multiple queries
        for ($i = 0; $i < 10; $i++) {
            $this->orchestrationDomain->processQuery($query, $context);
        }

        $metrics = $this->orchestrationDomain->getMetrics();

        $this->assertArrayHasKey('total_requests', $metrics);
        $this->assertArrayHasKey('successful_requests', $metrics);
        $this->assertArrayHasKey('failed_requests', $metrics);
        $this->assertArrayHasKey('average_response_time', $metrics);
        $this->assertArrayHasKey('stability_score', $metrics);
        $this->assertArrayHasKey('error_rate', $metrics);

        $this->assertEquals(10, $metrics['total_requests']);
        $this->assertEquals(10, $metrics['successful_requests']);
        $this->assertEquals(0, $metrics['failed_requests']);
        $this->assertEquals(1.0, $metrics['stability_score']);
        $this->assertEquals(0.0, $metrics['error_rate']);
        $this->assertGreaterThan(0, $metrics['average_response_time']);
    }

    #[Test]
    #[DataProvider('complexQueryProvider')]
    public function it_handles_complex_queries_correctly($query, $context, $expectedRole, $minConfidence, $description)
    {
        $this->lagEngine->shouldReceive('decompose')
            ->andReturn([
                'decomposition' => [['query' => $query, 'confidence' => 0.8]],
                'confidence' => 0.8,
                'execution_plan' => ['process'],
                'terminator_triggered' => false
            ]);

        $this->rcrRouter->shouldReceive('route')
            ->andReturn([
                'selected_role' => $expectedRole,
                'confidence' => $minConfidence,
                'routing_rationale' => ['primary_reason' => $description],
                'alternative_roles' => [],
                'estimated_performance' => ['estimated_response_time' => 180]
            ]);

        $result = $this->orchestrationDomain->processQuery($query, $context);

        $this->assertEquals('success', $result['status'], $description);
        $this->assertEquals($expectedRole, $result['rcr']['selected_role'], $description);
        $this->assertGreaterThanOrEqual($minConfidence, $result['rcr']['confidence'], $description);
    }

    public static function complexQueryProvider(): array
    {
        return [
            [
                'Design a distributed microservices architecture with event sourcing and CQRS patterns',
                ['domain' => 'technical', 'complexity' => 0.9],
                'specialist',
                0.8,
                'Complex technical architecture'
            ],
            [
                'Analyze customer behavior patterns across multiple touchpoints and generate actionable insights',
                ['data' => true, 'analysis' => true],
                'analyst',
                0.7,
                'Data analysis task'
            ],
            [
                'Coordinate the deployment of machine learning models across staging and production environments',
                ['workflow' => true, 'steps' => ['deploy', 'test', 'monitor']],
                'coordinator',
                0.75,
                'Workflow coordination'
            ],
            [
                'Synthesize research findings from cybersecurity, compliance, and business continuity domains',
                ['multiple_sources' => true, 'cross_domain' => true],
                'synthesizer',
                0.6,
                'Cross-domain synthesis'
            ],
            [
                'Validate the security compliance of our API gateway against SOC2 and PCI DSS requirements',
                ['validation' => true, 'compliance' => true],
                'validator',
                0.7,
                'Compliance validation'
            ]
        ];
    }

    #[Test]
    public function it_resets_metrics_correctly()
    {
        // Generate some metrics first
        $query = "Test query for metrics";
        $context = ['test' => true];

        $this->lagEngine->shouldReceive('decompose')
            ->andReturn([
                'decomposition' => [['query' => 'Test', 'confidence' => 0.8]],
                'confidence' => 0.8,
                'execution_plan' => ['test'],
                'terminator_triggered' => false
            ]);

        $this->rcrRouter->shouldReceive('route')
            ->andReturn([
                'selected_role' => 'coordinator',
                'confidence' => 0.8,
                'routing_rationale' => ['primary_reason' => 'Test'],
                'alternative_roles' => [],
                'estimated_performance' => ['estimated_response_time' => 100]
            ]);

        $this->orchestrationDomain->processQuery($query, $context);

        $metricsBefore = $this->orchestrationDomain->getMetrics();
        $this->assertGreaterThan(0, $metricsBefore['total_requests']);

        // Reset metrics
        $this->orchestrationDomain->resetMetrics();

        $metricsAfter = $this->orchestrationDomain->getMetrics();
        $this->assertEquals(0, $metricsAfter['total_requests']);
        $this->assertEquals(0, $metricsAfter['successful_requests']);
        $this->assertEquals(0, $metricsAfter['failed_requests']);
        $this->assertEquals(1.0, $metricsAfter['stability_score']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}