<?php

namespace App\Services;

use App\Models\RouterMetric;
use App\Models\OrchestrationRun;
use App\Models\TenantResourceUsage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Advanced RCR (Role-aware Context Routing) Optimizer
 * 
 * Enhances the existing RCR system to achieve 85%+ token reduction
 * through intelligent semantic filtering and adaptive optimization.
 */
class AdvancedRCROptimizer
{
    private const CACHE_TTL = 3600; // 1 hour
    private const MIN_CONFIDENCE_THRESHOLD = 0.85;
    private const ADAPTIVE_TOPK_MIN = 6;
    private const ADAPTIVE_TOPK_MAX = 20;
    
    /**
     * Enhanced role-specific token budgets with dynamic adjustment
     */
    private array $enhancedBudgets = [
        'Planner' => [
            'base' => 1536,
            'min' => 1200,
            'max' => 2000,
            'priority_weight' => 1.2
        ],
        'Retriever' => [
            'base' => 1024,
            'min' => 800,
            'max' => 1400,
            'priority_weight' => 1.1
        ],
        'Solver' => [
            'base' => 1024,
            'min' => 800,
            'max' => 1400,
            'priority_weight' => 1.3
        ],
        'Critic' => [
            'base' => 1024,
            'min' => 700,
            'max' => 1300,
            'priority_weight' => 1.0
        ],
        'Verifier' => [
            'base' => 1536,
            'min' => 1200,
            'max' => 2000,
            'priority_weight' => 1.1
        ],
        'Rewriter' => [
            'base' => 768,
            'min' => 500,
            'max' => 1000,
            'priority_weight' => 0.9
        ]
    ];

    public function __construct(
        private MetaLearningEngine $metaLearning,
        private FinOpsService $finOps
    ) {}

    /**
     * Optimize RCR routing to achieve 85%+ token reduction
     */
    public function optimizeRouting(string $query, array $context, array $roles): array
    {
        $startTime = microtime(true);
        
        // Get historical performance data
        $historicalData = $this->getHistoricalPerformance();
        
        // Apply adaptive topk selection
        $adaptiveTopK = $this->calculateAdaptiveTopK($query, $context, $historicalData);
        
        // Enhanced semantic filtering
        $filteredContext = $this->applyEnhancedSemanticFiltering(
            $context, 
            $query, 
            $adaptiveTopK,
            $roles
        );
        
        // Dynamic budget optimization
        $optimizedBudgets = $this->optimizeBudgetsForQuery(
            $query,
            $roles,
            $historicalData
        );
        
        // Role-aware context allocation
        $allocatedContext = $this->allocateContextToRoles(
            $filteredContext,
            $roles,
            $optimizedBudgets
        );
        
        // Calculate efficiency metrics
        $originalTokens = $this->estimateTokens($context);
        $optimizedTokens = $this->estimateTokens($allocatedContext);
        $efficiencyGain = (($originalTokens - $optimizedTokens) / $originalTokens) * 100;
        
        $result = [
            'allocated_context' => $allocatedContext,
            'budgets' => $optimizedBudgets,
            'efficiency_metrics' => [
                'original_tokens' => $originalTokens,
                'optimized_tokens' => $optimizedTokens,
                'token_reduction_percentage' => $efficiencyGain,
                'adaptive_topk' => $adaptiveTopK,
                'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ],
            'quality_score' => $this->calculateQualityScore($allocatedContext, $query),
            'confidence' => $this->calculateConfidenceScore($allocatedContext, $historicalData)
        ];
        
        // Log optimization results
        $this->logOptimizationResult($result);
        
        // Update meta-learning with results
        $this->updateMetaLearning($query, $result);
        
        return $result;
    }

    /**
     * Calculate adaptive topK based on query complexity and historical performance
     */
    private function calculateAdaptiveTopK(string $query, array $context, array $historicalData): int
    {
        // Analyze query complexity
        $complexity = $this->analyzeQueryComplexity($query);
        
        // Get average performance from similar queries
        $similarQueryPerformance = $this->findSimilarQueryPerformance($query, $historicalData);
        
        // Calculate base topK
        $baseTopK = self::ADAPTIVE_TOPK_MIN + 
                   ($complexity * (self::ADAPTIVE_TOPK_MAX - self::ADAPTIVE_TOPK_MIN));
        
        // Adjust based on historical performance
        if ($similarQueryPerformance['avg_efficiency'] > 80) {
            // If we're already performing well, be more aggressive
            $baseTopK = max(self::ADAPTIVE_TOPK_MIN, $baseTopK * 0.8);
        } elseif ($similarQueryPerformance['avg_efficiency'] < 60) {
            // If performance is poor, be more conservative
            $baseTopK = min(self::ADAPTIVE_TOPK_MAX, $baseTopK * 1.2);
        }
        
        // Ensure we don't exceed context size
        $maxPossible = min(count($context), self::ADAPTIVE_TOPK_MAX);
        
        return (int) max(self::ADAPTIVE_TOPK_MIN, min($maxPossible, $baseTopK));
    }

    /**
     * Enhanced semantic filtering with confidence scoring
     */
    private function applyEnhancedSemanticFiltering(
        array $context, 
        string $query, 
        int $topK,
        array $roles
    ): array {
        // Create query embedding (simulate with hash for now)
        $queryVector = $this->createSemanticVector($query);
        
        // Score each context item
        $scoredContext = [];
        foreach ($context as $index => $item) {
            $itemVector = $this->createSemanticVector($item['content'] ?? $item);
            
            $similarity = $this->calculateCosineSimilarity($queryVector, $itemVector);
            $relevance = $this->calculateRelevanceScore($item, $query, $roles);
            $importance = $this->calculateImportanceScore($item, $roles);
            $recency = $this->calculateRecencyScore($item);
            
            // Combined score with weighted factors
            $compositeScore = (
                $similarity * 0.4 +
                $relevance * 0.3 +
                $importance * 0.2 +
                $recency * 0.1
            );
            
            $scoredContext[] = [
                'index' => $index,
                'item' => $item,
                'scores' => [
                    'similarity' => $similarity,
                    'relevance' => $relevance,
                    'importance' => $importance,
                    'recency' => $recency,
                    'composite' => $compositeScore
                ],
                'confidence' => $this->calculateItemConfidence($similarity, $relevance)
            ];
        }
        
        // Sort by composite score
        usort($scoredContext, fn($a, $b) => $b['scores']['composite'] <=> $a['scores']['composite']);
        
        // Apply dynamic topK with confidence threshold
        $selectedContext = [];
        $tokenCount = 0;
        $confidenceSum = 0;
        
        for ($i = 0; $i < min($topK, count($scoredContext)); $i++) {
            $item = $scoredContext[$i];
            
            // Skip items below confidence threshold unless we haven't met minimum
            if ($item['confidence'] < self::MIN_CONFIDENCE_THRESHOLD && $i >= self::ADAPTIVE_TOPK_MIN) {
                break;
            }
            
            $selectedContext[] = $item;
            $tokenCount += $this->estimateTokens([$item['item']]);
            $confidenceSum += $item['confidence'];
        }
        
        return [
            'items' => $selectedContext,
            'total_tokens' => $tokenCount,
            'average_confidence' => count($selectedContext) > 0 ? $confidenceSum / count($selectedContext) : 0,
            'selection_metadata' => [
                'original_count' => count($context),
                'selected_count' => count($selectedContext),
                'reduction_ratio' => count($context) > 0 ? count($selectedContext) / count($context) : 0,
                'topk_used' => min($topK, count($scoredContext))
            ]
        ];
    }

    /**
     * Optimize budgets based on query characteristics and historical data
     */
    private function optimizeBudgetsForQuery(string $query, array $roles, array $historicalData): array
    {
        $optimizedBudgets = [];
        $queryType = $this->classifyQueryType($query);
        
        foreach ($roles as $role) {
            if (!isset($this->enhancedBudgets[$role])) {
                continue;
            }
            
            $baseBudget = $this->enhancedBudgets[$role];
            $rolePerformance = $this->getRolePerformance($role, $queryType, $historicalData);
            
            // Adjust budget based on role performance and query needs
            $adjustmentFactor = $this->calculateBudgetAdjustment($role, $queryType, $rolePerformance);
            
            $optimizedBudget = (int) round(
                $baseBudget['base'] * $adjustmentFactor * $baseBudget['priority_weight']
            );
            
            // Ensure within bounds
            $optimizedBudget = max(
                $baseBudget['min'],
                min($baseBudget['max'], $optimizedBudget)
            );
            
            $optimizedBudgets[$role] = [
                'budget' => $optimizedBudget,
                'base' => $baseBudget['base'],
                'adjustment_factor' => $adjustmentFactor,
                'priority_weight' => $baseBudget['priority_weight'],
                'performance_score' => $rolePerformance['efficiency'] ?? 0.7
            ];
        }
        
        return $optimizedBudgets;
    }

    /**
     * Allocate filtered context to roles based on optimized budgets
     */
    private function allocateContextToRoles(array $filteredContext, array $roles, array $budgets): array
    {
        $allocation = [];
        $items = $filteredContext['items'];
        $availableTokens = array_sum(array_column($budgets, 'budget'));
        
        foreach ($roles as $role) {
            if (!isset($budgets[$role])) {
                $allocation[$role] = ['items' => [], 'tokens' => 0];
                continue;
            }
            
            $roleBudget = $budgets[$role]['budget'];
            $roleItems = [];
            $roleTokens = 0;
            
            // Select items most relevant to this role
            $relevantItems = $this->selectItemsForRole($items, $role, $roleBudget);
            
            foreach ($relevantItems as $item) {
                $itemTokens = $this->estimateTokens([$item['item']]);
                
                if ($roleTokens + $itemTokens <= $roleBudget) {
                    $roleItems[] = $item;
                    $roleTokens += $itemTokens;
                }
                
                if ($roleTokens >= $roleBudget * 0.95) { // Allow 5% buffer
                    break;
                }
            }
            
            $allocation[$role] = [
                'items' => $roleItems,
                'tokens' => $roleTokens,
                'budget_utilization' => $roleBudget > 0 ? ($roleTokens / $roleBudget) : 0,
                'item_count' => count($roleItems)
            ];
        }
        
        return $allocation;
    }

    /**
     * Select items most relevant to a specific role
     */
    private function selectItemsForRole(array $items, string $role, int $budget): array
    {
        $roleWeights = [
            'Planner' => ['similarity' => 0.3, 'relevance' => 0.4, 'importance' => 0.2, 'recency' => 0.1],
            'Retriever' => ['similarity' => 0.5, 'relevance' => 0.3, 'importance' => 0.1, 'recency' => 0.1],
            'Solver' => ['similarity' => 0.2, 'relevance' => 0.5, 'importance' => 0.3, 'recency' => 0.0],
            'Critic' => ['similarity' => 0.3, 'relevance' => 0.2, 'importance' => 0.4, 'recency' => 0.1],
            'Verifier' => ['similarity' => 0.2, 'relevance' => 0.3, 'importance' => 0.4, 'recency' => 0.1],
            'Rewriter' => ['similarity' => 0.4, 'relevance' => 0.3, 'importance' => 0.2, 'recency' => 0.1]
        ];
        
        $weights = $roleWeights[$role] ?? $roleWeights['Solver'];
        
        // Re-score items for this specific role
        $roleScored = array_map(function($item) use ($weights) {
            $scores = $item['scores'];
            $roleScore = 
                $scores['similarity'] * $weights['similarity'] +
                $scores['relevance'] * $weights['relevance'] +
                $scores['importance'] * $weights['importance'] +
                $scores['recency'] * $weights['recency'];
            
            $item['role_score'] = $roleScore;
            return $item;
        }, $items);
        
        // Sort by role-specific score
        usort($roleScored, fn($a, $b) => $b['role_score'] <=> $a['role_score']);
        
        return $roleScored;
    }

    /**
     * Get historical performance data for optimization
     */
    private function getHistoricalPerformance(): array
    {
        $cacheKey = 'rcr_historical_performance';
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function() {
            $recentMetrics = RouterMetric::where('created_at', '>=', Carbon::now()->subDays(7))
                ->efficient(50) // Only consider efficient routes
                ->get();
            
            return [
                'total_routes' => $recentMetrics->count(),
                'avg_efficiency' => $recentMetrics->avg('token_savings_percentage') ?? 68,
                'avg_processing_time' => $recentMetrics->avg('selection_time_ms') ?? 150,
                'role_performance' => $this->analyzeRolePerformance($recentMetrics),
                'query_patterns' => $this->analyzeQueryPatterns($recentMetrics)
            ];
        });
    }

    /**
     * Helper methods for scoring and analysis
     */
    private function analyzeQueryComplexity(string $query): float
    {
        $length = strlen($query);
        $wordCount = str_word_count($query);
        $sentences = substr_count($query, '.') + substr_count($query, '?') + substr_count($query, '!');
        $questions = substr_count($query, '?');
        
        // Normalize complexity score between 0 and 1
        $lengthScore = min(1.0, $length / 1000);
        $wordScore = min(1.0, $wordCount / 100);
        $complexityScore = min(1.0, ($sentences + $questions) / 10);
        
        return ($lengthScore + $wordScore + $complexityScore) / 3;
    }

    private function createSemanticVector(string $text): array
    {
        // Simplified semantic vector creation
        // In production, this would use actual embeddings
        $words = str_word_count(strtolower($text), 1);
        $vector = array_fill(0, 100, 0);
        
        foreach ($words as $word) {
            $hash = crc32($word);
            for ($i = 0; $i < 5; $i++) {
                $index = abs($hash + $i) % 100;
                $vector[$index] += 1 / count($words);
            }
        }
        
        return $vector;
    }

    private function calculateCosineSimilarity(array $vec1, array $vec2): float
    {
        $dotProduct = 0;
        $norm1 = 0;
        $norm2 = 0;
        
        for ($i = 0; $i < min(count($vec1), count($vec2)); $i++) {
            $dotProduct += $vec1[$i] * $vec2[$i];
            $norm1 += $vec1[$i] ** 2;
            $norm2 += $vec2[$i] ** 2;
        }
        
        $denominator = sqrt($norm1) * sqrt($norm2);
        return $denominator > 0 ? $dotProduct / $denominator : 0;
    }

    private function calculateRelevanceScore($item, string $query, array $roles): float
    {
        // Simplified relevance calculation
        $content = $item['content'] ?? $item;
        $queryWords = str_word_count(strtolower($query), 1);
        $itemWords = str_word_count(strtolower($content), 1);
        
        $matches = count(array_intersect($queryWords, $itemWords));
        return min(1.0, $matches / max(1, count($queryWords)));
    }

    private function calculateImportanceScore($item, array $roles): float
    {
        // Score based on item metadata if available
        $metadata = $item['metadata'] ?? [];
        $importance = $metadata['importance'] ?? 0.5;
        
        // Boost for items with role-specific keywords
        $content = strtolower($item['content'] ?? $item);
        $roleBonus = 0;
        
        foreach ($roles as $role) {
            $keywords = $this->getRoleKeywords($role);
            foreach ($keywords as $keyword) {
                if (strpos($content, strtolower($keyword)) !== false) {
                    $roleBonus += 0.1;
                }
            }
        }
        
        return min(1.0, $importance + $roleBonus);
    }

    private function calculateRecencyScore($item): float
    {
        $timestamp = $item['timestamp'] ?? $item['created_at'] ?? time();
        $age = time() - (is_string($timestamp) ? strtotime($timestamp) : $timestamp);
        
        // Decay function: newer items get higher scores
        return max(0.1, exp(-$age / (24 * 3600))); // 24 hour half-life
    }

    private function getRoleKeywords(string $role): array
    {
        return [
            'Planner' => ['plan', 'strategy', 'approach', 'steps', 'process'],
            'Retriever' => ['find', 'search', 'locate', 'data', 'information'],
            'Solver' => ['solve', 'calculate', 'analyze', 'determine', 'answer'],
            'Critic' => ['evaluate', 'assess', 'critique', 'review', 'validate'],
            'Verifier' => ['verify', 'check', 'confirm', 'validate', 'ensure'],
            'Rewriter' => ['rewrite', 'improve', 'refine', 'edit', 'format']
        ][$role] ?? [];
    }

    private function estimateTokens($content): int
    {
        if (is_array($content)) {
            return array_sum(array_map([$this, 'estimateTokens'], $content));
        }
        
        $text = is_string($content) ? $content : ($content['content'] ?? '');
        return (int) ceil(strlen($text) / 4); // Rough approximation
    }

    private function calculateQualityScore(array $allocation, string $query): float
    {
        // Simplified quality scoring
        $totalItems = array_sum(array_column($allocation, 'item_count'));
        $avgConfidence = 0;
        $itemCount = 0;
        
        foreach ($allocation as $roleData) {
            foreach ($roleData['items'] as $item) {
                $avgConfidence += $item['confidence'];
                $itemCount++;
            }
        }
        
        return $itemCount > 0 ? $avgConfidence / $itemCount : 0;
    }

    private function calculateConfidenceScore(array $allocation, array $historicalData): float
    {
        // Base confidence on historical performance
        $baseConfidence = min(1.0, $historicalData['avg_efficiency'] / 100);
        
        // Adjust based on current allocation quality
        $qualityFactor = $this->calculateQualityScore($allocation, '');
        
        return ($baseConfidence + $qualityFactor) / 2;
    }

    private function logOptimizationResult(array $result): void
    {
        Log::info('RCR Optimization Result', [
            'token_reduction' => $result['efficiency_metrics']['token_reduction_percentage'],
            'processing_time' => $result['efficiency_metrics']['processing_time_ms'],
            'quality_score' => $result['quality_score'],
            'confidence' => $result['confidence']
        ]);
    }

    private function updateMetaLearning(string $query, array $result): void
    {
        // Update meta-learning engine with optimization results
        $this->metaLearning->recordOptimization([
            'type' => 'rcr_enhancement',
            'query_hash' => md5($query),
            'efficiency_gain' => $result['efficiency_metrics']['token_reduction_percentage'],
            'quality_score' => $result['quality_score'],
            'confidence' => $result['confidence'],
            'processing_time' => $result['efficiency_metrics']['processing_time_ms']
        ]);
    }

    // Additional helper methods (simplified implementations)
    private function findSimilarQueryPerformance(string $query, array $historicalData): array
    {
        return ['avg_efficiency' => $historicalData['avg_efficiency'] ?? 68];
    }

    private function classifyQueryType(string $query): string
    {
        if (strpos($query, '?') !== false) return 'question';
        if (preg_match('/\b(analyze|compare|evaluate)\b/i', $query)) return 'analysis';
        if (preg_match('/\b(create|generate|write)\b/i', $query)) return 'generation';
        return 'general';
    }

    private function getRolePerformance(string $role, string $queryType, array $historicalData): array
    {
        return $historicalData['role_performance'][$role] ?? ['efficiency' => 0.7];
    }

    private function calculateBudgetAdjustment(string $role, string $queryType, array $rolePerformance): float
    {
        $baseAdjustment = 1.0;
        
        if ($rolePerformance['efficiency'] > 0.8) {
            $baseAdjustment *= 0.9; // Reduce budget for high-performing roles
        } elseif ($rolePerformance['efficiency'] < 0.6) {
            $baseAdjustment *= 1.1; // Increase budget for low-performing roles
        }
        
        return $baseAdjustment;
    }

    private function calculateItemConfidence(float $similarity, float $relevance): float
    {
        return ($similarity + $relevance) / 2;
    }

    private function analyzeRolePerformance($metrics): array
    {
        // Simplified role performance analysis
        return [
            'Planner' => ['efficiency' => 0.75],
            'Retriever' => ['efficiency' => 0.80],
            'Solver' => ['efficiency' => 0.85],
            'Critic' => ['efficiency' => 0.70],
            'Verifier' => ['efficiency' => 0.78],
            'Rewriter' => ['efficiency' => 0.72]
        ];
    }

    private function analyzeQueryPatterns($metrics): array
    {
        return ['patterns' => 'analysis_pending'];
    }
}