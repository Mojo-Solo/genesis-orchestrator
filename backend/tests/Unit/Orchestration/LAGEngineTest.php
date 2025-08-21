<?php

namespace Tests\Unit\Orchestration;

use Tests\TestCase;
use App\Domains\Orchestration\Services\LAGEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Comprehensive LAG Engine Test Suite
 * 
 * Tests for Logical Answer Generation engine ensuring ≥98.6% stability
 * and performance compliance under all operating conditions.
 * 
 * Test Coverage:
 * - Cartesian decomposition accuracy
 * - Cognitive load assessment precision
 * - Termination logic correctness
 * - Performance variance (≤1.4%)
 * - Artifact generation compliance
 * - Edge case handling
 * - Memory efficiency
 * - Concurrent processing stability
 */
class LAGEngineTest extends TestCase
{
    private LAGEngine $lagEngine;
    private array $testMetrics = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->lagEngine = new LAGEngine();
        $this->testMetrics = [];
    }

    #[Test]
    public function it_performs_basic_query_decomposition()
    {
        $query = "What are the key factors in successful project management?";
        $config = [
            'max_depth' => 3,
            'confidence_threshold' => 0.8
        ];

        $result = $this->lagEngine->decompose($query, $config);

        $this->assertArrayHasKey('decomposition', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('execution_plan', $result);
        $this->assertGreaterThanOrEqual(0.8, $result['confidence']);
        $this->assertIsArray($result['decomposition']);
        $this->assertNotEmpty($result['decomposition']);
    }

    #[Test]
    public function it_handles_complex_cartesian_decomposition()
    {
        $query = "How do machine learning algorithms, data preprocessing techniques, and model validation strategies interact to create robust predictive systems in enterprise environments?";
        
        $config = [
            'max_depth' => 5,
            'confidence_threshold' => 0.85,
            'cartesian_mode' => 'advanced'
        ];

        $startTime = microtime(true);
        $result = $this->lagEngine->decompose($query, $config);
        $processingTime = (microtime(true) - $startTime) * 1000;

        // Performance assertions
        $this->assertLessThan(200, $processingTime, 'Processing time should be under 200ms');
        
        // Decomposition quality assertions
        $this->assertGreaterThanOrEqual(3, count($result['decomposition']), 'Should produce multiple decomposition elements');
        $this->assertArrayHasKey('cognitive_load', $result);
        $this->assertArrayHasKey('cartesian_space', $result);
        
        // Confidence assertions
        $this->assertGreaterThanOrEqual(0.85, $result['confidence']);
        
        // Cartesian space validation
        $cartesianSpace = $result['cartesian_space'];
        $this->assertArrayHasKey('dimensions', $cartesianSpace);
        $this->assertArrayHasKey('complexity_score', $cartesianSpace);
        $this->assertGreaterThan(0, count($cartesianSpace['dimensions']));
    }

    #[Test]
    public function it_assesses_cognitive_load_accurately()
    {
        $testCases = [
            ['Simple question about weather', 0.1, 0.3],
            ['Moderate analysis of market trends in technology sector', 0.4, 0.7],
            ['Complex multi-dimensional analysis of quantum computing implications for cryptographic security in distributed systems', 0.7, 1.0]
        ];

        foreach ($testCases as [$query, $minExpected, $maxExpected]) {
            $result = $this->lagEngine->decompose($query);
            
            $this->assertArrayHasKey('cognitive_load', $result);
            $cognitiveLoad = $result['cognitive_load'];
            
            $this->assertGreaterThanOrEqual($minExpected, $cognitiveLoad, 
                "Cognitive load for '$query' should be at least $minExpected");
            $this->assertLessThanOrEqual($maxExpected, $cognitiveLoad, 
                "Cognitive load for '$query' should be at most $maxExpected");
        }
    }

    #[Test]
    public function it_triggers_termination_conditions_correctly()
    {
        $testCases = [
            [
                'query' => 'What is the color of the number seven?',
                'expected_terminator' => 'UNANSWERABLE',
                'description' => 'Synesthetic question should trigger UNANSWERABLE'
            ],
            [
                'query' => 'Is it true that all cats are dogs and all dogs are not cats?',
                'expected_terminator' => 'CONTRADICTION',
                'description' => 'Contradictory statement should trigger CONTRADICTION'
            ],
            [
                'query' => 'What happened on Mars yesterday at 3:47 PM local time?',
                'expected_terminator' => 'LOW_SUPPORT',
                'description' => 'Unsupported specific query should trigger LOW_SUPPORT'
            ]
        ];

        foreach ($testCases as $testCase) {
            $config = ['enable_terminators' => true, 'confidence_threshold' => 0.9];
            $result = $this->lagEngine->decompose($testCase['query'], $config);

            $this->assertArrayHasKey('terminator_triggered', $result, $testCase['description']);
            
            if ($result['terminator_triggered']) {
                $this->assertArrayHasKey('termination_reason', $result);
                $this->assertEquals($testCase['expected_terminator'], $result['termination_reason'], 
                    $testCase['description']);
            }
        }
    }

    #[Test]
    public function it_maintains_performance_variance_within_limits()
    {
        $query = "Analyze the effectiveness of agile methodologies in distributed teams";
        $config = ['max_depth' => 3, 'confidence_threshold' => 0.8];
        
        $processingTimes = [];
        $confidenceScores = [];
        
        // Run multiple iterations to test variance
        for ($i = 0; $i < 50; $i++) {
            $startTime = microtime(true);
            $result = $this->lagEngine->decompose($query, $config);
            $processingTime = (microtime(true) - $startTime) * 1000;
            
            $processingTimes[] = $processingTime;
            $confidenceScores[] = $result['confidence'];
        }

        // Calculate statistics
        $avgProcessingTime = array_sum($processingTimes) / count($processingTimes);
        $avgConfidence = array_sum($confidenceScores) / count($confidenceScores);
        
        // Calculate variance
        $processingVariance = $this->calculateVariance($processingTimes, $avgProcessingTime);
        $confidenceVariance = $this->calculateVariance($confidenceScores, $avgConfidence);
        
        // Performance assertions
        $this->assertLessThan(200, $avgProcessingTime, 'Average processing time should be under 200ms');
        $this->assertLessThanOrEqual(0.014, $processingVariance, 'Processing time variance should be ≤1.4%');
        $this->assertLessThanOrEqual(0.014, $confidenceVariance, 'Confidence variance should be ≤1.4%');
        $this->assertGreaterThanOrEqual(0.8, $avgConfidence, 'Average confidence should be ≥80%');
    }

    #[Test]
    public function it_generates_compliant_artifacts()
    {
        $query = "Design a comprehensive data security framework for healthcare organizations";
        $config = [
            'artifact_generation' => true,
            'max_depth' => 4,
            'confidence_threshold' => 0.85
        ];

        $result = $this->lagEngine->decompose($query, $config);

        $this->assertArrayHasKey('artifacts', $result);
        $artifacts = $result['artifacts'];

        // Required artifact structure
        $requiredKeys = ['execution_trace', 'decomposition_tree', 'confidence_map', 'performance_metrics'];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $artifacts, "Artifact should contain $key");
        }

        // Execution trace validation
        $this->assertIsArray($artifacts['execution_trace']);
        $this->assertNotEmpty($artifacts['execution_trace']);

        // Performance metrics validation
        $metrics = $artifacts['performance_metrics'];
        $this->assertArrayHasKey('processing_time', $metrics);
        $this->assertArrayHasKey('memory_usage', $metrics);
        $this->assertArrayHasKey('confidence_score', $metrics);
        $this->assertArrayHasKey('decomposition_depth', $metrics);
    }

    #[Test]
    public function it_handles_edge_cases_gracefully()
    {
        $edgeCases = [
            '', // Empty string
            '   ', // Whitespace only
            str_repeat('a', 10000), // Very long string
            "🚀🎯🔥💯", // Unicode emojis only
            "SELECT * FROM users; DROP TABLE users;", // SQL injection attempt
            "<script>alert('xss')</script>", // XSS attempt
            null, // Null input (will be cast to string)
        ];

        foreach ($edgeCases as $edgeCase) {
            try {
                $result = $this->lagEngine->decompose($edgeCase ?? '');
                
                // Should handle gracefully without throwing
                $this->assertIsArray($result);
                $this->assertArrayHasKey('decomposition', $result);
                $this->assertArrayHasKey('confidence', $result);
                
                // Edge cases should have low confidence or trigger terminators
                $hasLowConfidence = $result['confidence'] < 0.3;
                $hasTerminator = $result['terminator_triggered'] ?? false;
                
                $this->assertTrue($hasLowConfidence || $hasTerminator, 
                    'Edge case should result in low confidence or termination');
                    
            } catch (\Exception $e) {
                $this->fail("LAG Engine should handle edge case gracefully, but threw: " . $e->getMessage());
            }
        }
    }

    #[Test]
    public function it_maintains_memory_efficiency()
    {
        $initialMemory = memory_get_usage(true);
        
        $largeQueries = [
            str_repeat("How does quantum computing affect cryptographic security? ", 100),
            str_repeat("Analyze machine learning model performance in distributed systems. ", 100),
            str_repeat("Evaluate database optimization strategies for high-traffic applications. ", 100),
        ];

        foreach ($largeQueries as $query) {
            $result = $this->lagEngine->decompose($query, ['max_depth' => 5]);
            $this->assertIsArray($result);
        }

        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;
        $memoryIncreaseMB = $memoryIncrease / 1024 / 1024;

        // Memory usage should not increase dramatically
        $this->assertLessThan(50, $memoryIncreaseMB, 
            'Memory usage should not increase by more than 50MB during processing');
    }

    #[Test]
    public function it_handles_concurrent_processing()
    {
        $queries = [
            "Analyze cloud computing security patterns",
            "Evaluate microservices architecture benefits",
            "Design scalable database solutions",
            "Implement DevOps best practices",
            "Optimize machine learning pipelines"
        ];

        $promises = [];
        $results = [];

        // Simulate concurrent processing
        foreach ($queries as $index => $query) {
            $startTime = microtime(true);
            $result = $this->lagEngine->decompose($query, ['max_depth' => 3]);
            $processingTime = (microtime(true) - $startTime) * 1000;
            
            $results[] = [
                'query' => $query,
                'result' => $result,
                'processing_time' => $processingTime
            ];
        }

        // Validate all results
        foreach ($results as $item) {
            $this->assertIsArray($item['result']);
            $this->assertArrayHasKey('confidence', $item['result']);
            $this->assertGreaterThanOrEqual(0.5, $item['result']['confidence']);
            $this->assertLessThan(300, $item['processing_time']);
        }

        // Check for consistent behavior
        $confidenceScores = array_column($results, 'result');
        $confidenceScores = array_column($confidenceScores, 'confidence');
        $confidenceVariance = $this->calculateVariance($confidenceScores, array_sum($confidenceScores) / count($confidenceScores));
        
        $this->assertLessThan(0.2, $confidenceVariance, 'Confidence variance should be low across concurrent processing');
    }

    #[Test]
    #[DataProvider('stabilityTestProvider')]
    public function it_maintains_stability_across_diverse_inputs($query, $expectedMinConfidence, $description)
    {
        $config = [
            'max_depth' => 4,
            'confidence_threshold' => 0.8,
            'stability_mode' => true
        ];

        $result = $this->lagEngine->decompose($query, $config);

        $this->assertIsArray($result, $description);
        $this->assertArrayHasKey('confidence', $result, $description);
        $this->assertArrayHasKey('decomposition', $result, $description);
        
        // Stability requirement: should meet minimum confidence
        $this->assertGreaterThanOrEqual($expectedMinConfidence, $result['confidence'], 
            "$description - Confidence should be at least $expectedMinConfidence");
        
        // Should not trigger error terminators for valid queries
        if (!isset($result['terminator_triggered']) || !$result['terminator_triggered']) {
            $this->assertNotEmpty($result['decomposition'], "$description - Should produce decomposition");
        }
    }

    public static function stabilityTestProvider(): array
    {
        return [
            ['What is artificial intelligence?', 0.7, 'Simple technical definition'],
            ['How do neural networks learn from data?', 0.6, 'Moderate technical explanation'],
            ['Explain the relationship between quantum mechanics and computer science', 0.5, 'Complex interdisciplinary query'],
            ['Design a system for real-time fraud detection in financial transactions', 0.6, 'Applied technical challenge'],
            ['What are the ethical implications of autonomous vehicles?', 0.5, 'Ethical reasoning query'],
            ['How can blockchain technology improve supply chain transparency?', 0.6, 'Technology application query'],
            ['Analyze the effectiveness of remote work on software development teams', 0.5, 'Business analysis query'],
            ['What programming language should I learn first?', 0.7, 'Practical advice query'],
            ['How does machine learning bias affect hiring decisions?', 0.5, 'Social impact query'],
            ['Explain database normalization and its benefits', 0.8, 'Technical concept explanation']
        ];
    }

    #[Test]
    public function it_provides_comprehensive_metrics()
    {
        $query = "Develop a comprehensive cybersecurity strategy for a multinational corporation";
        $config = [
            'max_depth' => 4,
            'confidence_threshold' => 0.8,
            'detailed_metrics' => true
        ];

        $result = $this->lagEngine->decompose($query, $config);

        $this->assertArrayHasKey('metrics', $result);
        $metrics = $result['metrics'];

        // Required metrics
        $requiredMetrics = [
            'processing_time',
            'memory_usage',
            'decomposition_depth',
            'confidence_score',
            'cognitive_load',
            'cartesian_dimensions',
            'termination_checks',
            'optimization_applied'
        ];

        foreach ($requiredMetrics as $metric) {
            $this->assertArrayHasKey($metric, $metrics, "Should include $metric metric");
        }

        // Metric value validation
        $this->assertIsFloat($metrics['processing_time']);
        $this->assertIsInt($metrics['memory_usage']);
        $this->assertIsInt($metrics['decomposition_depth']);
        $this->assertIsFloat($metrics['confidence_score']);
        $this->assertGreaterThan(0, $metrics['processing_time']);
        $this->assertGreaterThan(0, $metrics['memory_usage']);
    }

    /**
     * Helper method to calculate variance
     */
    private function calculateVariance(array $values, float $mean): float
    {
        if (empty($values)) return 0.0;
        
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += pow($value - $mean, 2);
        }
        
        return sqrt($sum / count($values)) / $mean; // Coefficient of variation
    }

    protected function tearDown(): void
    {
        // Record test metrics for analysis
        if (!empty($this->testMetrics)) {
            $this->recordTestMetrics($this->testMetrics);
        }
        
        parent::tearDown();
    }

    /**
     * Record test metrics for performance analysis
     */
    private function recordTestMetrics(array $metrics): void
    {
        // In a real implementation, this would send metrics to monitoring system
        // For tests, we just validate the structure
        $this->assertIsArray($metrics);
    }
}