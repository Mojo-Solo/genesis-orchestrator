<?php

namespace App\Domains\Orchestration\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RCR (Role-aware Context Routing) Router
 * 
 * Intelligent context routing system that dynamically assigns processing roles
 * based on query characteristics, context requirements, and available resources.
 * 
 * Key Features:
 * - Dynamic role assignment with confidence scoring
 * - Context-aware routing with multi-dimensional analysis
 * - Load balancing with adaptive capacity management
 * - Performance optimization with ≤200ms target latency
 * - Real-time quality monitoring with ≥98.6% accuracy
 * 
 * Optimization Targets:
 * - Routing accuracy: ≥98.6%
 * - Response latency: ≤200ms
 * - Resource utilization: ≤75%
 * - Context precision: ≥95%
 */
class RCRRouter
{
    /**
     * Available processing roles with capabilities
     */
    private array $roles = [
        'analyst' => [
            'capabilities' => ['data_analysis', 'pattern_recognition', 'statistical_processing'],
            'complexity_max' => 0.8,
            'load_capacity' => 100,
            'response_time_avg' => 150
        ],
        'synthesizer' => [
            'capabilities' => ['information_synthesis', 'cross_domain_reasoning', 'insight_generation'],
            'complexity_max' => 0.9,
            'load_capacity' => 75,
            'response_time_avg' => 200
        ],
        'specialist' => [
            'capabilities' => ['domain_expertise', 'technical_analysis', 'detailed_research'],
            'complexity_max' => 1.0,
            'load_capacity' => 50,
            'response_time_avg' => 300
        ],
        'coordinator' => [
            'capabilities' => ['task_orchestration', 'workflow_management', 'resource_allocation'],
            'complexity_max' => 0.7,
            'load_capacity' => 200,
            'response_time_avg' => 100
        ],
        'validator' => [
            'capabilities' => ['quality_assurance', 'compliance_checking', 'result_validation'],
            'complexity_max' => 0.6,
            'load_capacity' => 150,
            'response_time_avg' => 120
        ]
    ];
    
    /**
     * Context routing weights for different dimensions
     */
    private array $routingWeights = [
        'complexity' => 0.25,
        'domain_specificity' => 0.20,
        'response_time_requirement' => 0.20,
        'resource_availability' => 0.15,
        'quality_requirement' => 0.10,
        'context_richness' => 0.10
    ];
    
    /**
     * Performance metrics tracking
     */
    private array $metrics = [
        'routing_accuracy' => 0.0,
        'average_latency' => 0.0,
        'resource_utilization' => 0.0,
        'context_precision' => 0.0,
        'total_requests' => 0,
        'successful_routes' => 0,
        'failed_routes' => 0,
        'role_distribution' => []
    ];
    
    /**
     * Current role load tracking
     */
    private array $currentLoad = [];
    
    public function __construct()
    {
        $this->initializeMetrics();
        $this->loadCurrentState();
    }
    
    /**
     * Route query to optimal processing role
     */
    public function route(string $query, array $context = [], array $requirements = []): array
    {
        $startTime = microtime(true);
        $this->metrics['total_requests']++;
        
        try {
            // Analyze query characteristics
            $queryAnalysis = $this->analyzeQuery($query);
            
            // Assess context requirements
            $contextAnalysis = $this->analyzeContext($context);
            
            // Calculate role scores
            $roleScores = $this->calculateRoleScores($queryAnalysis, $contextAnalysis, $requirements);
            
            // Select optimal role
            $selectedRole = $this->selectOptimalRole($roleScores);
            
            // Update load tracking
            $this->updateLoadTracking($selectedRole);
            
            // Generate routing result
            $result = $this->generateRoutingResult($selectedRole, $roleScores, $queryAnalysis, $contextAnalysis);
            
            // Update metrics
            $processingTime = (microtime(true) - $startTime) * 1000;
            $this->updateMetrics($result, $processingTime, true);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->metrics['failed_routes']++;
            $processingTime = (microtime(true) - $startTime) * 1000;
            $this->updateMetrics(null, $processingTime, false);
            
            Log::error('RCR routing failed', [
                'query' => $query,
                'error' => $e->getMessage(),
                'processing_time' => $processingTime
            ]);
            
            // Fallback to coordinator role
            return $this->generateFallbackResult($query, $context, $e);
        }
    }
    
    /**
     * Analyze query characteristics for routing
     */
    private function analyzeQuery(string $query): array
    {
        // Calculate complexity score
        $complexity = $this->calculateQueryComplexity($query);
        
        // Detect domain specificity
        $domainSpecificity = $this->detectDomainSpecificity($query);
        
        // Assess information density
        $informationDensity = $this->calculateInformationDensity($query);
        
        // Identify query type
        $queryType = $this->classifyQueryType($query);
        
        // Extract key concepts
        $keyConcepts = $this->extractKeyConcepts($query);
        
        return [
            'complexity' => $complexity,
            'domain_specificity' => $domainSpecificity,
            'information_density' => $informationDensity,
            'query_type' => $queryType,
            'key_concepts' => $keyConcepts,
            'length' => strlen($query),
            'word_count' => str_word_count($query)
        ];
    }
    
    /**
     * Analyze context requirements for routing
     */
    private function analyzeContext(array $context): array
    {
        // Assess context richness
        $richness = $this->calculateContextRichness($context);
        
        // Determine required capabilities
        $requiredCapabilities = $this->identifyRequiredCapabilities($context);
        
        // Calculate context complexity
        $contextComplexity = $this->calculateContextComplexity($context);
        
        // Assess temporal requirements
        $temporalRequirements = $this->assessTemporalRequirements($context);
        
        return [
            'richness' => $richness,
            'required_capabilities' => $requiredCapabilities,
            'complexity' => $contextComplexity,
            'temporal_requirements' => $temporalRequirements,
            'data_volume' => count($context),
            'nested_levels' => $this->countNestedLevels($context)
        ];
    }
    
    /**
     * Calculate role scores for routing decision
     */
    private function calculateRoleScores(array $queryAnalysis, array $contextAnalysis, array $requirements): array
    {
        $scores = [];
        
        foreach ($this->roles as $roleName => $roleConfig) {
            $score = 0.0;
            
            // Complexity matching
            $complexityScore = $this->calculateComplexityScore($roleConfig, $queryAnalysis['complexity']);
            $score += $complexityScore * $this->routingWeights['complexity'];
            
            // Capability matching
            $capabilityScore = $this->calculateCapabilityScore($roleConfig, $contextAnalysis['required_capabilities']);
            $score += $capabilityScore * $this->routingWeights['domain_specificity'];
            
            // Response time assessment
            $responseTimeScore = $this->calculateResponseTimeScore($roleConfig, $requirements);
            $score += $responseTimeScore * $this->routingWeights['response_time_requirement'];
            
            // Resource availability
            $resourceScore = $this->calculateResourceScore($roleName, $roleConfig);
            $score += $resourceScore * $this->routingWeights['resource_availability'];
            
            // Quality assessment
            $qualityScore = $this->calculateQualityScore($roleConfig, $requirements);
            $score += $qualityScore * $this->routingWeights['quality_requirement'];
            
            // Context richness handling
            $contextScore = $this->calculateContextScore($roleConfig, $contextAnalysis['richness']);
            $score += $contextScore * $this->routingWeights['context_richness'];
            
            $scores[$roleName] = [
                'total_score' => $score,
                'complexity_score' => $complexityScore,
                'capability_score' => $capabilityScore,
                'response_time_score' => $responseTimeScore,
                'resource_score' => $resourceScore,
                'quality_score' => $qualityScore,
                'context_score' => $contextScore
            ];
        }
        
        // Normalize scores
        $maxScore = max(array_column($scores, 'total_score'));
        if ($maxScore > 0) {
            foreach ($scores as $roleName => $roleScore) {
                $scores[$roleName]['normalized_score'] = $roleScore['total_score'] / $maxScore;
            }
        }
        
        return $scores;
    }
    
    /**
     * Select optimal role based on scores and constraints
     */
    private function selectOptimalRole(array $roleScores): string
    {
        // Sort roles by normalized score
        uasort($roleScores, fn($a, $b) => $b['normalized_score'] <=> $a['normalized_score']);
        
        // Select the highest scoring role that meets constraints
        foreach ($roleScores as $roleName => $scores) {
            if ($this->checkRoleConstraints($roleName, $scores)) {
                return $roleName;
            }
        }
        
        // Fallback to coordinator if no role meets constraints
        return 'coordinator';
    }
    
    /**
     * Generate comprehensive routing result
     */
    private function generateRoutingResult(string $selectedRole, array $roleScores, array $queryAnalysis, array $contextAnalysis): array
    {
        return [
            'selected_role' => $selectedRole,
            'confidence' => $roleScores[$selectedRole]['normalized_score'],
            'role_config' => $this->roles[$selectedRole],
            'routing_rationale' => $this->generateRoutingRationale($selectedRole, $roleScores),
            'query_analysis' => $queryAnalysis,
            'context_analysis' => $contextAnalysis,
            'alternative_roles' => $this->getAlternativeRoles($roleScores, $selectedRole),
            'estimated_performance' => $this->estimatePerformance($selectedRole),
            'routing_timestamp' => now(),
            'routing_id' => uniqid('rcr_', true)
        ];
    }
    
    /**
     * Calculate query complexity score
     */
    private function calculateQueryComplexity(string $query): float
    {
        $complexity = 0.0;
        
        // Base complexity from length
        $lengthComplexity = min(strlen($query) / 1000, 0.3);
        $complexity += $lengthComplexity;
        
        // Syntactic complexity
        $syntacticComplexity = $this->calculateSyntacticComplexity($query);
        $complexity += $syntacticComplexity * 0.3;
        
        // Semantic complexity
        $semanticComplexity = $this->calculateSemanticComplexity($query);
        $complexity += $semanticComplexity * 0.4;
        
        return min($complexity, 1.0);
    }
    
    /**
     * Calculate syntactic complexity
     */
    private function calculateSyntacticComplexity(string $query): float
    {
        $complexity = 0.0;
        
        // Count nested structures
        $nestedStructures = substr_count($query, '(') + substr_count($query, '[') + substr_count($query, '{');
        $complexity += min($nestedStructures / 10, 0.3);
        
        // Count conjunctions and logical operators
        $logicalOperators = preg_match_all('/\b(and|or|but|if|then|else|when|where|because)\b/i', $query);
        $complexity += min($logicalOperators / 5, 0.3);
        
        // Count question structures
        $questionMarkers = substr_count($query, '?') + preg_match_all('/\b(what|how|why|when|where|who)\b/i', $query);
        $complexity += min($questionMarkers / 5, 0.4);
        
        return min($complexity, 1.0);
    }
    
    /**
     * Calculate semantic complexity
     */
    private function calculateSemanticComplexity(string $query): float
    {
        $complexity = 0.0;
        
        // Domain-specific terminology density
        $technicalTerms = preg_match_all('/\b[A-Z]{2,}\b|\b\w+[A-Z]\w*\b/', $query);
        $complexity += min($technicalTerms / str_word_count($query), 0.4);
        
        // Abstract concept indicators
        $abstractConcepts = preg_match_all('/\b(concept|theory|principle|methodology|framework|strategy)\b/i', $query);
        $complexity += min($abstractConcepts / 3, 0.3);
        
        // Multi-domain references
        $domainReferences = $this->countDomainReferences($query);
        $complexity += min($domainReferences / 3, 0.3);
        
        return min($complexity, 1.0);
    }
    
    /**
     * Detect domain specificity
     */
    private function detectDomainSpecificity(string $query): float
    {
        $domains = [
            'technical' => ['API', 'database', 'algorithm', 'protocol', 'framework', 'architecture'],
            'business' => ['strategy', 'revenue', 'market', 'customer', 'growth', 'ROI'],
            'scientific' => ['hypothesis', 'analysis', 'research', 'methodology', 'validation'],
            'legal' => ['compliance', 'regulation', 'policy', 'governance', 'audit'],
            'financial' => ['budget', 'cost', 'investment', 'profit', 'expense', 'financial']
        ];
        
        $specificityScore = 0.0;
        $queryLower = strtolower($query);
        
        foreach ($domains as $domain => $terms) {
            $domainMatches = 0;
            foreach ($terms as $term) {
                if (strpos($queryLower, strtolower($term)) !== false) {
                    $domainMatches++;
                }
            }
            if ($domainMatches > 0) {
                $specificityScore += $domainMatches / count($terms);
            }
        }
        
        return min($specificityScore, 1.0);
    }
    
    /**
     * Calculate information density
     */
    private function calculateInformationDensity(string $query): float
    {
        $wordCount = str_word_count($query);
        if ($wordCount === 0) return 0.0;
        
        // Unique word ratio
        $words = str_word_count($query, 1);
        $uniqueWords = array_unique($words);
        $uniqueRatio = count($uniqueWords) / count($words);
        
        // Information-bearing words
        $informationWords = preg_match_all('/\b(?!the|and|or|but|in|on|at|to|for|of|with|by)\w+\b/i', $query);
        $informationRatio = $informationWords / $wordCount;
        
        return ($uniqueRatio + $informationRatio) / 2;
    }
    
    /**
     * Classify query type
     */
    private function classifyQueryType(string $query): string
    {
        $queryLower = strtolower($query);
        
        if (preg_match('/\b(what|how|why|when|where|who)\b/', $queryLower)) {
            return 'interrogative';
        } elseif (preg_match('/\b(analyze|compare|evaluate|assess|review)\b/', $queryLower)) {
            return 'analytical';
        } elseif (preg_match('/\b(create|generate|build|develop|design)\b/', $queryLower)) {
            return 'generative';
        } elseif (preg_match('/\b(explain|describe|define|clarify)\b/', $queryLower)) {
            return 'explanatory';
        } elseif (preg_match('/\b(optimize|improve|enhance|fix|solve)\b/', $queryLower)) {
            return 'optimization';
        } else {
            return 'general';
        }
    }
    
    /**
     * Extract key concepts from query
     */
    private function extractKeyConcepts(string $query): array
    {
        // Simple keyword extraction (in production, would use NLP)
        $words = str_word_count($query, 1);
        $stopWords = ['the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'a', 'an'];
        
        $concepts = array_filter($words, function($word) use ($stopWords) {
            return !in_array(strtolower($word), $stopWords) && strlen($word) > 3;
        });
        
        return array_slice(array_unique($concepts), 0, 10);
    }
    
    /**
     * Calculate context richness
     */
    private function calculateContextRichness(array $context): float
    {
        if (empty($context)) return 0.0;
        
        $richness = 0.0;
        
        // Data volume contribution
        $dataVolume = count($context);
        $richness += min($dataVolume / 20, 0.3);
        
        // Data diversity contribution
        $dataTypes = [];
        foreach ($context as $value) {
            $dataTypes[] = gettype($value);
        }
        $typeRatio = count(array_unique($dataTypes)) / count($dataTypes);
        $richness += $typeRatio * 0.3;
        
        // Nested complexity contribution
        $nestedLevels = $this->countNestedLevels($context);
        $richness += min($nestedLevels / 5, 0.4);
        
        return min($richness, 1.0);
    }
    
    /**
     * Count nested levels in array
     */
    private function countNestedLevels(array $data, int $currentLevel = 0): int
    {
        $maxLevel = $currentLevel;
        
        foreach ($data as $value) {
            if (is_array($value)) {
                $nestedLevel = $this->countNestedLevels($value, $currentLevel + 1);
                $maxLevel = max($maxLevel, $nestedLevel);
            }
        }
        
        return $maxLevel;
    }
    
    /**
     * Identify required capabilities from context
     */
    private function identifyRequiredCapabilities(array $context): array
    {
        $capabilities = [];
        
        // Check for data analysis requirements
        if (isset($context['data']) || isset($context['dataset'])) {
            $capabilities[] = 'data_analysis';
        }
        
        // Check for synthesis requirements
        if (isset($context['multiple_sources']) || isset($context['cross_domain'])) {
            $capabilities[] = 'information_synthesis';
        }
        
        // Check for domain expertise requirements
        if (isset($context['domain']) || isset($context['specialty'])) {
            $capabilities[] = 'domain_expertise';
        }
        
        // Check for orchestration requirements
        if (isset($context['workflow']) || isset($context['steps'])) {
            $capabilities[] = 'task_orchestration';
        }
        
        // Check for validation requirements
        if (isset($context['validation']) || isset($context['compliance'])) {
            $capabilities[] = 'quality_assurance';
        }
        
        return array_unique($capabilities);
    }
    
    /**
     * Calculate context complexity
     */
    private function calculateContextComplexity(array $context): float
    {
        if (empty($context)) return 0.0;
        
        $complexity = 0.0;
        
        // Size complexity
        $sizeComplexity = min(count($context) / 50, 0.4);
        $complexity += $sizeComplexity;
        
        // Structure complexity
        $structureComplexity = min($this->countNestedLevels($context) / 5, 0.3);
        $complexity += $structureComplexity;
        
        // Content complexity
        $contentComplexity = $this->calculateContentComplexity($context);
        $complexity += $contentComplexity * 0.3;
        
        return min($complexity, 1.0);
    }
    
    /**
     * Calculate content complexity
     */
    private function calculateContentComplexity(array $context): float
    {
        $complexity = 0.0;
        $totalItems = 0;
        
        foreach ($context as $key => $value) {
            $totalItems++;
            
            if (is_string($value)) {
                $complexity += min(strlen($value) / 1000, 0.1);
            } elseif (is_array($value)) {
                $complexity += min(count($value) / 10, 0.1);
            } elseif (is_object($value)) {
                $complexity += 0.1;
            }
        }
        
        return $totalItems > 0 ? $complexity / $totalItems : 0.0;
    }
    
    /**
     * Assess temporal requirements
     */
    private function assessTemporalRequirements(array $context): array
    {
        return [
            'urgency' => $context['urgency'] ?? 'normal',
            'deadline' => $context['deadline'] ?? null,
            'real_time' => $context['real_time'] ?? false,
            'batch_processing' => $context['batch_processing'] ?? false
        ];
    }
    
    /**
     * Calculate complexity score for role matching
     */
    private function calculateComplexityScore(array $roleConfig, float $queryComplexity): float
    {
        $roleMaxComplexity = $roleConfig['complexity_max'];
        
        if ($queryComplexity <= $roleMaxComplexity) {
            // Perfect match or role can handle complexity
            return 1.0 - abs($queryComplexity - $roleMaxComplexity) / $roleMaxComplexity;
        } else {
            // Query too complex for role
            return max(0.0, 1.0 - ($queryComplexity - $roleMaxComplexity));
        }
    }
    
    /**
     * Calculate capability matching score
     */
    private function calculateCapabilityScore(array $roleConfig, array $requiredCapabilities): float
    {
        if (empty($requiredCapabilities)) return 1.0;
        
        $roleCapabilities = $roleConfig['capabilities'];
        $matchedCapabilities = array_intersect($requiredCapabilities, $roleCapabilities);
        
        return count($matchedCapabilities) / count($requiredCapabilities);
    }
    
    /**
     * Calculate response time score
     */
    private function calculateResponseTimeScore(array $roleConfig, array $requirements): float
    {
        $requiredResponseTime = $requirements['max_response_time'] ?? 300; // ms
        $roleAvgTime = $roleConfig['response_time_avg'];
        
        if ($roleAvgTime <= $requiredResponseTime) {
            return 1.0 - ($roleAvgTime / $requiredResponseTime) * 0.5;
        } else {
            return max(0.0, 1.0 - ($roleAvgTime - $requiredResponseTime) / $requiredResponseTime);
        }
    }
    
    /**
     * Calculate resource availability score
     */
    private function calculateResourceScore(string $roleName, array $roleConfig): float
    {
        $currentLoad = $this->getCurrentLoad($roleName);
        $capacity = $roleConfig['load_capacity'];
        
        $utilization = $currentLoad / $capacity;
        
        if ($utilization < 0.5) {
            return 1.0;
        } elseif ($utilization < 0.75) {
            return 1.0 - (($utilization - 0.5) / 0.25) * 0.3;
        } else {
            return max(0.0, 1.0 - $utilization);
        }
    }
    
    /**
     * Calculate quality score
     */
    private function calculateQualityScore(array $roleConfig, array $requirements): float
    {
        $requiredQuality = $requirements['min_quality'] ?? 0.8;
        $roleComplexityMax = $roleConfig['complexity_max'];
        
        // Higher complexity capacity generally correlates with quality
        $qualityScore = $roleComplexityMax;
        
        return $qualityScore >= $requiredQuality ? 1.0 : $qualityScore / $requiredQuality;
    }
    
    /**
     * Calculate context handling score
     */
    private function calculateContextScore(array $roleConfig, float $contextRichness): float
    {
        $roleComplexityMax = $roleConfig['complexity_max'];
        
        if ($contextRichness <= $roleComplexityMax) {
            return 1.0;
        } else {
            return max(0.0, 1.0 - ($contextRichness - $roleComplexityMax));
        }
    }
    
    /**
     * Check role constraints
     */
    private function checkRoleConstraints(string $roleName, array $scores): bool
    {
        // Check minimum score threshold
        if ($scores['normalized_score'] < 0.3) {
            return false;
        }
        
        // Check load capacity
        $currentLoad = $this->getCurrentLoad($roleName);
        $capacity = $this->roles[$roleName]['load_capacity'];
        
        if ($currentLoad >= $capacity) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Get current role load
     */
    private function getCurrentLoad(string $roleName): int
    {
        return $this->currentLoad[$roleName] ?? 0;
    }
    
    /**
     * Update load tracking
     */
    private function updateLoadTracking(string $selectedRole): void
    {
        $this->currentLoad[$selectedRole] = ($this->currentLoad[$selectedRole] ?? 0) + 1;
        
        // Cache current load state
        Cache::put('rcr_load_tracking', $this->currentLoad, 300); // 5 minutes
    }
    
    /**
     * Generate routing rationale
     */
    private function generateRoutingRationale(string $selectedRole, array $roleScores): array
    {
        $selectedScores = $roleScores[$selectedRole];
        
        return [
            'primary_reason' => $this->getPrimaryReason($selectedScores),
            'confidence_factors' => $this->getConfidenceFactors($selectedScores),
            'alternative_considered' => count($roleScores) - 1,
            'decision_factors' => [
                'complexity_match' => $selectedScores['complexity_score'],
                'capability_match' => $selectedScores['capability_score'],
                'resource_availability' => $selectedScores['resource_score']
            ]
        ];
    }
    
    /**
     * Get primary routing reason
     */
    private function getPrimaryReason(array $scores): string
    {
        $maxScore = max([
            'complexity' => $scores['complexity_score'],
            'capability' => $scores['capability_score'],
            'response_time' => $scores['response_time_score'],
            'resource' => $scores['resource_score'],
            'quality' => $scores['quality_score'],
            'context' => $scores['context_score']
        ]);
        
        $reasons = [
            'complexity' => 'Optimal complexity matching',
            'capability' => 'Best capability alignment',
            'response_time' => 'Superior response time',
            'resource' => 'High resource availability',
            'quality' => 'Quality requirements match',
            'context' => 'Excellent context handling'
        ];
        
        $primaryFactor = array_search($maxScore, [
            'complexity' => $scores['complexity_score'],
            'capability' => $scores['capability_score'],
            'response_time' => $scores['response_time_score'],
            'resource' => $scores['resource_score'],
            'quality' => $scores['quality_score'],
            'context' => $scores['context_score']
        ]);
        
        return $reasons[$primaryFactor] ?? 'Overall score optimization';
    }
    
    /**
     * Get confidence factors
     */
    private function getConfidenceFactors(array $scores): array
    {
        return [
            'high_confidence' => array_filter($scores, fn($score) => $score > 0.8),
            'medium_confidence' => array_filter($scores, fn($score) => $score >= 0.5 && $score <= 0.8),
            'low_confidence' => array_filter($scores, fn($score) => $score < 0.5)
        ];
    }
    
    /**
     * Get alternative roles
     */
    private function getAlternativeRoles(array $roleScores, string $selectedRole): array
    {
        $alternatives = array_filter($roleScores, fn($role) => $role !== $selectedRole, ARRAY_FILTER_USE_KEY);
        
        // Sort by score and return top 2 alternatives
        uasort($alternatives, fn($a, $b) => $b['normalized_score'] <=> $a['normalized_score']);
        
        return array_slice($alternatives, 0, 2, true);
    }
    
    /**
     * Estimate performance for selected role
     */
    private function estimatePerformance(string $selectedRole): array
    {
        $roleConfig = $this->roles[$selectedRole];
        $currentLoad = $this->getCurrentLoad($selectedRole);
        
        // Adjust estimates based on current load
        $loadFactor = min($currentLoad / $roleConfig['load_capacity'], 1.0);
        $estimatedResponseTime = $roleConfig['response_time_avg'] * (1 + $loadFactor * 0.5);
        
        return [
            'estimated_response_time' => $estimatedResponseTime,
            'expected_quality' => $roleConfig['complexity_max'],
            'resource_utilization' => $loadFactor,
            'confidence_level' => max(0.5, 1.0 - $loadFactor * 0.3)
        ];
    }
    
    /**
     * Generate fallback result
     */
    private function generateFallbackResult(string $query, array $context, \Exception $error): array
    {
        return [
            'selected_role' => 'coordinator',
            'confidence' => 0.5,
            'role_config' => $this->roles['coordinator'],
            'routing_rationale' => [
                'primary_reason' => 'Fallback due to routing failure',
                'error_message' => $error->getMessage()
            ],
            'fallback_mode' => true,
            'routing_timestamp' => now(),
            'routing_id' => uniqid('rcr_fallback_', true)
        ];
    }
    
    /**
     * Count domain references in query
     */
    private function countDomainReferences(string $query): int
    {
        $domains = ['technical', 'business', 'scientific', 'financial', 'legal', 'medical', 'educational'];
        $count = 0;
        
        foreach ($domains as $domain) {
            if (stripos($query, $domain) !== false) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Update performance metrics
     */
    private function updateMetrics(?array $result, float $processingTime, bool $successful): void
    {
        if ($successful) {
            $this->metrics['successful_routes']++;
            
            // Update accuracy (simplified calculation)
            $this->metrics['routing_accuracy'] = $this->metrics['successful_routes'] / $this->metrics['total_requests'];
            
            // Track role distribution
            if ($result) {
                $role = $result['selected_role'];
                $this->metrics['role_distribution'][$role] = ($this->metrics['role_distribution'][$role] ?? 0) + 1;
            }
        }
        
        // Update average latency
        $this->metrics['average_latency'] = (
            ($this->metrics['average_latency'] * ($this->metrics['total_requests'] - 1)) + $processingTime
        ) / $this->metrics['total_requests'];
        
        // Update resource utilization
        $totalLoad = array_sum($this->currentLoad);
        $totalCapacity = array_sum(array_column($this->roles, 'load_capacity'));
        $this->metrics['resource_utilization'] = $totalCapacity > 0 ? $totalLoad / $totalCapacity : 0.0;
        
        // Cache updated metrics
        Cache::put('rcr_metrics', $this->metrics, 600); // 10 minutes
    }
    
    /**
     * Get current routing metrics
     */
    public function getMetrics(): array
    {
        return array_merge($this->metrics, [
            'target_accuracy' => 0.986,
            'target_latency' => 200.0,
            'target_utilization' => 0.75,
            'performance_status' => $this->getPerformanceStatus()
        ]);
    }
    
    /**
     * Get performance status
     */
    private function getPerformanceStatus(): string
    {
        $accuracy = $this->metrics['routing_accuracy'];
        $latency = $this->metrics['average_latency'];
        $utilization = $this->metrics['resource_utilization'];
        
        if ($accuracy >= 0.986 && $latency <= 200 && $utilization <= 0.75) {
            return 'optimal';
        } elseif ($accuracy >= 0.95 && $latency <= 300 && $utilization <= 0.85) {
            return 'good';
        } elseif ($accuracy >= 0.90 && $latency <= 500 && $utilization <= 0.95) {
            return 'acceptable';
        } else {
            return 'needs_optimization';
        }
    }
    
    /**
     * Reset routing metrics
     */
    public function resetMetrics(): void
    {
        $this->initializeMetrics();
        Cache::forget('rcr_metrics');
        Cache::forget('rcr_load_tracking');
    }
    
    /**
     * Initialize metrics tracking
     */
    private function initializeMetrics(): void
    {
        $this->metrics = [
            'routing_accuracy' => 0.0,
            'average_latency' => 0.0,
            'resource_utilization' => 0.0,
            'context_precision' => 0.0,
            'total_requests' => 0,
            'successful_routes' => 0,
            'failed_routes' => 0,
            'role_distribution' => []
        ];
        
        $this->currentLoad = [];
        foreach (array_keys($this->roles) as $role) {
            $this->currentLoad[$role] = 0;
        }
    }
    
    /**
     * Load current state from cache
     */
    private function loadCurrentState(): void
    {
        $cachedMetrics = Cache::get('rcr_metrics');
        if ($cachedMetrics) {
            $this->metrics = array_merge($this->metrics, $cachedMetrics);
        }
        
        $cachedLoad = Cache::get('rcr_load_tracking');
        if ($cachedLoad) {
            $this->currentLoad = array_merge($this->currentLoad, $cachedLoad);
        }
    }
    
    /**
     * Get health status
     */
    public function getHealthStatus(): array
    {
        $performanceStatus = $this->getPerformanceStatus();
        
        return [
            'status' => $performanceStatus === 'optimal' ? 'healthy' : 'degraded',
            'performance_status' => $performanceStatus,
            'metrics' => $this->getMetrics(),
            'last_updated' => now(),
            'recommendations' => $this->generateHealthRecommendations()
        ];
    }
    
    /**
     * Generate health recommendations
     */
    private function generateHealthRecommendations(): array
    {
        $recommendations = [];
        
        if ($this->metrics['routing_accuracy'] < 0.986) {
            $recommendations[] = 'Improve routing accuracy through better role scoring algorithms';
        }
        
        if ($this->metrics['average_latency'] > 200) {
            $recommendations[] = 'Optimize routing latency through caching and algorithm improvements';
        }
        
        if ($this->metrics['resource_utilization'] > 0.75) {
            $recommendations[] = 'Scale up role capacity or implement load balancing';
        }
        
        return $recommendations;
    }
}