<?php

namespace Tests\Unit\Orchestration;

use Tests\TestCase;
use App\Domains\Orchestration\Services\RCRRouter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Comprehensive RCR Router Test Suite
 * 
 * Tests for Role-aware Context Routing ensuring ≥98.6% routing accuracy
 * and optimal role selection under all operating conditions.
 * 
 * Test Coverage:
 * - Role selection accuracy (≥98.6%)
 * - Context analysis precision
 * - Load balancing effectiveness
 * - Response time compliance (≤200ms)
 * - Multi-dimensional routing weights
 * - Edge case handling
 * - Concurrent routing stability
 * - Resource utilization optimization
 */
class RCRRouterTest extends TestCase
{
    private RCRRouter $rcrRouter;
    private array $testMetrics = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->rcrRouter = new RCRRouter();
        $this->testMetrics = [];
    }

    #[Test]
    public function it_performs_basic_role_routing()
    {
        $query = "Analyze the performance metrics of our database system";
        $context = [
            'domain' => 'technical',
            'complexity' => 0.6,
            'urgency' => 'normal'
        ];
        $requirements = [
            'max_response_time' => 200,
            'min_quality' => 0.8
        ];

        $result = $this->rcrRouter->route($query, $context, $requirements);

        $this->assertArrayHasKey('selected_role', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('routing_rationale', $result);
        $this->assertArrayHasKey('estimated_performance', $result);
        
        $this->assertIsString($result['selected_role']);
        $this->assertGreaterThanOrEqual(0.6, $result['confidence']);
        $this->assertIsArray($result['routing_rationale']);
        $this->assertIsArray($result['estimated_performance']);
    }

    #[Test]
    public function it_selects_analyst_role_for_data_analysis_queries()
    {
        $testCases = [
            "Analyze quarterly sales performance data",
            "What patterns exist in user behavior analytics?",
            "Generate statistical reports from customer data",
            "Examine trends in website traffic metrics"
        ];

        foreach ($testCases as $query) {
            $context = ['data' => true, 'analysis_required' => true];
            $result = $this->rcrRouter->route($query, $context);

            // Should prefer analyst role for data analysis
            $this->assertEquals('analyst', $result['selected_role'], 
                "Query '$query' should route to analyst role");
            $this->assertGreaterThanOrEqual(0.7, $result['confidence']);
        }
    }

    #[Test]
    public function it_selects_synthesizer_role_for_cross_domain_queries()
    {
        $testCases = [
            "How do machine learning and cybersecurity intersect in modern applications?",
            "Compare cloud computing strategies across different industries",
            "Synthesize research from multiple domains on AI ethics",
            "Integrate findings from marketing, sales, and customer service data"
        ];

        foreach ($testCases as $query) {
            $context = [
                'multiple_sources' => true,
                'cross_domain' => true,
                'complexity' => 0.8
            ];
            $result = $this->rcrRouter->route($query, $context);

            $this->assertEquals('synthesizer', $result['selected_role'], 
                "Query '$query' should route to synthesizer role");
            $this->assertGreaterThanOrEqual(0.6, $result['confidence']);
        }
    }

    #[Test]
    public function it_selects_specialist_role_for_complex_domain_queries()
    {
        $testCases = [
            "Implement advanced cryptographic protocols for blockchain security",
            "Design quantum-resistant encryption algorithms",
            "Optimize distributed database sharding strategies for petabyte-scale data",
            "Develop real-time machine learning inference pipelines with microsecond latency"
        ];

        foreach ($testCases as $query) {
            $context = [
                'domain' => 'technical',
                'specialty' => true,
                'complexity' => 0.9
            ];
            $result = $this->rcrRouter->route($query, $context);

            $this->assertEquals('specialist', $result['selected_role'], 
                "Query '$query' should route to specialist role");
            $this->assertGreaterThanOrEqual(0.5, $result['confidence']);
        }
    }

    #[Test]
    public function it_selects_coordinator_role_for_workflow_queries()
    {
        $testCases = [
            "Manage the deployment pipeline for our microservices architecture",
            "Coordinate the integration testing across multiple development teams",
            "Orchestrate the data migration from legacy systems",
            "Plan the rollout strategy for the new application release"
        ];

        foreach ($testCases as $query) {
            $context = [
                'workflow' => true,
                'steps' => ['plan', 'execute', 'monitor'],
                'complexity' => 0.5
            ];
            $result = $this->rcrRouter->route($query, $context);

            $this->assertEquals('coordinator', $result['selected_role'], 
                "Query '$query' should route to coordinator role");
            $this->assertGreaterThanOrEqual(0.7, $result['confidence']);
        }
    }

    #[Test]
    public function it_selects_validator_role_for_quality_assurance_queries()
    {
        $testCases = [
            "Validate the security compliance of our authentication system",
            "Review the code quality standards in the development process",
            "Audit the data privacy measures in our user management system",
            "Verify the performance benchmarks meet the specified requirements"
        ];

        foreach ($testCases as $query) {
            $context = [
                'validation' => true,
                'compliance' => true,
                'quality_check' => true
            ];
            $result = $this->rcrRouter->route($query, $context);

            $this->assertEquals('validator', $result['selected_role'], 
                "Query '$query' should route to validator role");
            $this->assertGreaterThanOrEqual(0.6, $result['confidence']);
        }
    }

    #[Test]
    public function it_maintains_response_time_under_200ms()
    {
        $query = "Design a scalable microservices architecture for e-commerce platform";
        $context = [
            'complexity' => 0.8,
            'domain' => 'technical',
            'urgency' => 'high'
        ];

        $processingTimes = [];
        
        for ($i = 0; $i < 100; $i++) {
            $startTime = microtime(true);
            $result = $this->rcrRouter->route($query, $context);
            $processingTime = (microtime(true) - $startTime) * 1000;
            
            $processingTimes[] = $processingTime;
            $this->assertIsArray($result);
        }

        $avgProcessingTime = array_sum($processingTimes) / count($processingTimes);
        $maxProcessingTime = max($processingTimes);
        
        $this->assertLessThan(200, $avgProcessingTime, 'Average processing time should be under 200ms');
        $this->assertLessThan(300, $maxProcessingTime, 'Maximum processing time should be under 300ms');
    }

    #[Test]
    public function it_achieves_routing_accuracy_target()
    {
        $testCases = $this->getKnownOptimalRoutingCases();
        $correctRoutes = 0;
        $totalRoutes = count($testCases);

        foreach ($testCases as $testCase) {
            $result = $this->rcrRouter->route($testCase['query'], $testCase['context']);
            
            if ($result['selected_role'] === $testCase['expected_role']) {
                $correctRoutes++;
            }
            
            // Should always have reasonable confidence
            $this->assertGreaterThanOrEqual(0.3, $result['confidence'], 
                "Should have minimum confidence for query: " . $testCase['query']);
        }

        $accuracy = $correctRoutes / $totalRoutes;
        $this->assertGreaterThanOrEqual(0.986, $accuracy, 
            "Routing accuracy should be ≥98.6%, got " . ($accuracy * 100) . "%");
    }

    #[Test]
    public function it_handles_load_balancing_effectively()
    {
        $queries = [
            "Analyze user behavior patterns",
            "Review system performance metrics", 
            "Validate security protocols",
            "Coordinate deployment process",
            "Synthesize market research data"
        ];

        $roleDistribution = [];
        
        // Simulate load across roles
        for ($i = 0; $i < 50; $i++) {
            foreach ($queries as $query) {
                $result = $this->rcrRouter->route($query, ['load_test' => true]);
                $role = $result['selected_role'];
                
                if (!isset($roleDistribution[$role])) {
                    $roleDistribution[$role] = 0;
                }
                $roleDistribution[$role]++;
            }
        }

        // Check that load is distributed (no single role should handle >60% of requests)
        $totalRequests = array_sum($roleDistribution);
        foreach ($roleDistribution as $role => $count) {
            $percentage = $count / $totalRequests;
            $this->assertLessThan(0.6, $percentage, 
                "Role $role should not handle more than 60% of requests, got " . ($percentage * 100) . "%");
        }

        // Should use multiple roles
        $this->assertGreaterThanOrEqual(3, count($roleDistribution), 
            'Should distribute load across multiple roles');
    }

    #[Test]
    public function it_respects_resource_constraints()
    {
        $query = "Perform comprehensive system analysis with detailed reporting";
        
        // Test with various resource constraints
        $constraints = [
            ['max_response_time' => 100, 'expected_role' => 'coordinator'], // Fast response needed
            ['max_response_time' => 500, 'expected_complexity' => 'high'], // Can handle complex routing
            ['min_quality' => 0.95, 'expected_role' => 'specialist'], // High quality needed
            ['min_quality' => 0.7, 'flexible' => true] // More flexible requirements
        ];

        foreach ($constraints as $constraint) {
            $result = $this->rcrRouter->route($query, [], $constraint);
            
            if (isset($constraint['expected_role'])) {
                $this->assertEquals($constraint['expected_role'], $result['selected_role'], 
                    'Should respect role constraints');
            }
            
            // Should respect response time constraints
            if (isset($constraint['max_response_time'])) {
                $estimatedTime = $result['estimated_performance']['estimated_response_time'] ?? 200;
                $this->assertLessThanOrEqual($constraint['max_response_time'] * 1.2, $estimatedTime, 
                    'Should respect response time constraints (with 20% tolerance)');
            }
        }
    }

    #[Test]
    public function it_provides_routing_rationale()
    {
        $query = "Optimize database query performance for high-traffic application";
        $context = [
            'domain' => 'technical',
            'performance_critical' => true,
            'complexity' => 0.7
        ];

        $result = $this->rcrRouter->route($query, $context);

        $this->assertArrayHasKey('routing_rationale', $result);
        $rationale = $result['routing_rationale'];

        $this->assertArrayHasKey('primary_reason', $rationale);
        $this->assertArrayHasKey('confidence_factors', $rationale);
        $this->assertArrayHasKey('decision_factors', $rationale);

        $this->assertIsString($rationale['primary_reason']);
        $this->assertIsArray($rationale['confidence_factors']);
        $this->assertIsArray($rationale['decision_factors']);

        // Decision factors should include key scoring components
        $decisionFactors = $rationale['decision_factors'];
        $this->assertArrayHasKey('complexity_match', $decisionFactors);
        $this->assertArrayHasKey('capability_match', $decisionFactors);
        $this->assertArrayHasKey('resource_availability', $decisionFactors);
    }

    #[Test]
    public function it_provides_alternative_roles()
    {
        $query = "Develop machine learning model for fraud detection";
        $context = ['ml' => true, 'security' => true];

        $result = $this->rcrRouter->route($query, $context);

        $this->assertArrayHasKey('alternative_roles', $result);
        $alternatives = $result['alternative_roles'];

        $this->assertIsArray($alternatives);
        $this->assertLessThanOrEqual(2, count($alternatives), 
            'Should provide at most 2 alternative roles');

        foreach ($alternatives as $role => $scores) {
            $this->assertIsString($role);
            $this->assertIsArray($scores);
            $this->assertArrayHasKey('normalized_score', $scores);
            $this->assertIsFloat($scores['normalized_score']);
        }
    }

    #[Test]
    public function it_handles_edge_cases_gracefully()
    {
        $edgeCases = [
            ['query' => '', 'context' => [], 'description' => 'Empty query'],
            ['query' => '   ', 'context' => [], 'description' => 'Whitespace only'],
            ['query' => str_repeat('x', 10000), 'context' => [], 'description' => 'Very long query'],
            ['query' => 'Test query', 'context' => ['invalid' => null], 'description' => 'Null context values'],
            ['query' => '🚀🎯💻', 'context' => [], 'description' => 'Emoji only query'],
        ];

        foreach ($edgeCases as $edgeCase) {
            try {
                $result = $this->rcrRouter->route($edgeCase['query'], $edgeCase['context']);
                
                $this->assertIsArray($result, $edgeCase['description']);
                $this->assertArrayHasKey('selected_role', $result, $edgeCase['description']);
                $this->assertArrayHasKey('confidence', $result, $edgeCase['description']);
                
                // Edge cases should fall back to coordinator
                $this->assertEquals('coordinator', $result['selected_role'], 
                    $edgeCase['description'] . ' - should fallback to coordinator');
                    
            } catch (\Exception $e) {
                $this->fail("Should handle edge case gracefully: " . $edgeCase['description'] . 
                          ". Error: " . $e->getMessage());
            }
        }
    }

    #[Test]
    public function it_maintains_consistent_routing_for_similar_queries()
    {
        $similarQueries = [
            "Analyze sales performance data for Q4",
            "Review Q4 sales performance metrics",
            "Examine fourth quarter sales analytics",
            "Evaluate sales data from the last quarter"
        ];

        $routes = [];
        foreach ($similarQueries as $query) {
            $result = $this->rcrRouter->route($query, ['domain' => 'business']);
            $routes[] = $result['selected_role'];
        }

        // All similar queries should route to the same role
        $uniqueRoles = array_unique($routes);
        $this->assertEquals(1, count($uniqueRoles), 
            'Similar queries should route to the same role consistently');
    }

    #[Test]
    public function it_adapts_routing_based_on_context_richness()
    {
        $query = "Implement user authentication system";
        
        $contexts = [
            ['simple' => true], // Minimal context
            ['domain' => 'security', 'complexity' => 0.6], // Moderate context
            ['domain' => 'security', 'complexity' => 0.8, 'compliance' => 'SOC2', 'multi_factor' => true] // Rich context
        ];

        $results = [];
        foreach ($contexts as $context) {
            $result = $this->rcrRouter->route($query, $context);
            $results[] = $result;
        }

        // Richer context should lead to higher confidence
        $this->assertLessThan($results[1]['confidence'], $results[2]['confidence'], 
            'Richer context should increase routing confidence');
        
        // Context analysis should reflect the richness
        foreach ($results as $i => $result) {
            $this->assertArrayHasKey('context_analysis', $result);
            $contextAnalysis = $result['context_analysis'];
            $this->assertArrayHasKey('richness', $contextAnalysis);
            
            // Later contexts should have higher richness scores
            if ($i > 0) {
                $this->assertGreaterThanOrEqual($results[$i-1]['context_analysis']['richness'], 
                                                $contextAnalysis['richness']);
            }
        }
    }

    #[Test]
    #[DataProvider('performanceTestProvider')]
    public function it_maintains_performance_under_load($queryCount, $maxAvgTime, $description)
    {
        $query = "Analyze system performance and generate optimization recommendations";
        $context = ['domain' => 'technical', 'complexity' => 0.7];
        
        $processingTimes = [];
        $successCount = 0;
        
        for ($i = 0; $i < $queryCount; $i++) {
            try {
                $startTime = microtime(true);
                $result = $this->rcrRouter->route($query, $context);
                $processingTime = (microtime(true) - $startTime) * 1000;
                
                $processingTimes[] = $processingTime;
                if (is_array($result) && isset($result['selected_role'])) {
                    $successCount++;
                }
                
            } catch (\Exception $e) {
                // Track failures but don't fail the test immediately
            }
        }

        $avgTime = array_sum($processingTimes) / count($processingTimes);
        $successRate = $successCount / $queryCount;
        
        $this->assertGreaterThanOrEqual(0.99, $successRate, 
            "$description - Success rate should be ≥99%");
        $this->assertLessThan($maxAvgTime, $avgTime, 
            "$description - Average time should be under {$maxAvgTime}ms");
    }

    public static function performanceTestProvider(): array
    {
        return [
            [10, 200, 'Light load test'],
            [100, 250, 'Medium load test'],
            [500, 300, 'Heavy load test'],
        ];
    }

    #[Test]
    public function it_provides_comprehensive_metrics()
    {
        $query = "Design distributed system architecture with fault tolerance";
        $context = ['distributed' => true, 'fault_tolerance' => true];

        $result = $this->rcrRouter->route($query, $context);

        // Should include routing metrics
        $metrics = $this->rcrRouter->getMetrics();
        
        $requiredMetrics = [
            'routing_accuracy',
            'average_latency',
            'resource_utilization',
            'total_requests',
            'successful_routes',
            'failed_routes',
            'role_distribution'
        ];

        foreach ($requiredMetrics as $metric) {
            $this->assertArrayHasKey($metric, $metrics, "Should include $metric metric");
        }

        $this->assertIsFloat($metrics['routing_accuracy']);
        $this->assertIsFloat($metrics['average_latency']);
        $this->assertIsFloat($metrics['resource_utilization']);
        $this->assertIsArray($metrics['role_distribution']);
    }

    #[Test]
    public function it_maintains_health_status()
    {
        // Perform several routing operations to establish baseline
        for ($i = 0; $i < 10; $i++) {
            $this->rcrRouter->route("Test query $i", ['test' => true]);
        }

        $health = $this->rcrRouter->getHealthStatus();

        $this->assertArrayHasKey('status', $health);
        $this->assertArrayHasKey('performance_status', $health);
        $this->assertArrayHasKey('metrics', $health);
        $this->assertArrayHasKey('recommendations', $health);

        $this->assertContains($health['status'], ['healthy', 'degraded']);
        $this->assertContains($health['performance_status'], ['optimal', 'good', 'acceptable', 'needs_optimization']);
        $this->assertIsArray($health['recommendations']);
    }

    /**
     * Get test cases with known optimal routing decisions
     */
    private function getKnownOptimalRoutingCases(): array
    {
        return [
            // Data analysis cases - should route to analyst
            ['query' => 'Analyze customer purchase patterns', 'context' => ['data' => true], 'expected_role' => 'analyst'],
            ['query' => 'Generate sales performance reports', 'context' => ['data' => true], 'expected_role' => 'analyst'],
            ['query' => 'Examine website traffic trends', 'context' => ['data' => true], 'expected_role' => 'analyst'],
            
            // Cross-domain synthesis - should route to synthesizer
            ['query' => 'Compare AI strategies across industries', 'context' => ['multiple_sources' => true], 'expected_role' => 'synthesizer'],
            ['query' => 'Integrate marketing and sales insights', 'context' => ['cross_domain' => true], 'expected_role' => 'synthesizer'],
            
            // Complex technical - should route to specialist
            ['query' => 'Implement quantum encryption protocols', 'context' => ['domain' => 'security', 'complexity' => 0.9], 'expected_role' => 'specialist'],
            ['query' => 'Design distributed consensus algorithms', 'context' => ['specialty' => true], 'expected_role' => 'specialist'],
            
            // Workflow management - should route to coordinator
            ['query' => 'Plan software deployment pipeline', 'context' => ['workflow' => true], 'expected_role' => 'coordinator'],
            ['query' => 'Coordinate team project milestones', 'context' => ['steps' => ['plan', 'execute']], 'expected_role' => 'coordinator'],
            
            // Quality assurance - should route to validator
            ['query' => 'Audit security compliance measures', 'context' => ['validation' => true], 'expected_role' => 'validator'],
            ['query' => 'Review code quality standards', 'context' => ['compliance' => true], 'expected_role' => 'validator'],
            
            // Simple queries - should route to coordinator (default)
            ['query' => 'What is cloud computing?', 'context' => [], 'expected_role' => 'coordinator'],
            ['query' => 'Hello', 'context' => [], 'expected_role' => 'coordinator'],
        ];
    }

    protected function tearDown(): void
    {
        // Reset router metrics for clean test state
        $this->rcrRouter->resetMetrics();
        parent::tearDown();
    }
}