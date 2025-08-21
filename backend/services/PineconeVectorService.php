<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\VectorEmbedding;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

/**
 * Pinecone Vector Service
 * 
 * Handles vector embeddings storage and semantic search using Pinecone,
 * integrated with OpenAI embeddings for intelligent meeting insights.
 */
class PineconeVectorService
{
    private const OPENAI_EMBEDDINGS_ENDPOINT = 'https://api.openai.com/v1/embeddings';
    private const EMBEDDING_MODEL = 'text-embedding-3-large';
    private const EMBEDDING_DIMENSIONS = 3072; // text-embedding-3-large dimensions
    private const MAX_BATCH_SIZE = 100;
    private const MAX_TOKENS_PER_REQUEST = 8000;
    
    /**
     * Pinecone configuration
     */
    private array $config = [
        'api' => [
            'timeout' => 30,
            'retry_attempts' => 3,
            'rate_limit' => 1000, // requests per minute
        ],
        'indexing' => [
            'batch_size' => 25,
            'max_metadata_size' => 40960, // 40KB
            'namespace_strategy' => 'tenant_based',
            'similarity_metric' => 'cosine',
        ],
        'search' => [
            'default_top_k' => 10,
            'max_top_k' => 100,
            'similarity_threshold' => 0.7,
            'include_metadata' => true,
            'include_values' => false,
        ],
        'caching' => [
            'embedding_ttl' => 86400, // 24 hours
            'search_ttl' => 1800,     // 30 minutes
            'index_stats_ttl' => 3600, // 1 hour
        ]
    ];

    public function __construct(
        private InsightGenerationService $insightService
    ) {}

    /**
     * Generate embedding for text content
     */
    public function generateEmbedding(string $content, Tenant $tenant, array $options = []): array
    {
        $cacheKey = "embedding:" . hash('sha256', $content);
        
        return Cache::remember($cacheKey, $this->config['caching']['embedding_ttl'], function() use ($content, $tenant, $options) {
            $model = $options['model'] ?? self::EMBEDDING_MODEL;
            $dimensions = $options['dimensions'] ?? self::EMBEDDING_DIMENSIONS;
            
            // Ensure content is within token limits
            $content = $this->truncateToTokenLimit($content);
            
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->getOpenAIKey($tenant)}",
                'Content-Type' => 'application/json'
            ])
            ->timeout($this->config['api']['timeout'])
            ->retry($this->config['api']['retry_attempts'])
            ->post(self::OPENAI_EMBEDDINGS_ENDPOINT, [
                'model' => $model,
                'input' => $content,
                'dimensions' => $dimensions,
                'encoding_format' => 'float'
            ]);
            
            if (!$response->successful()) {
                Log::error('OpenAI embeddings request failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'tenant_id' => $tenant->id
                ]);
                
                throw new Exception("Failed to generate embedding: {$response->status()}");
            }
            
            $data = $response->json();
            
            if (!isset($data['data'][0]['embedding'])) {
                throw new Exception("Invalid embedding response format");
            }
            
            return [
                'embedding' => $data['data'][0]['embedding'],
                'model' => $model,
                'dimensions' => count($data['data'][0]['embedding']),
                'usage' => $data['usage'] ?? null
            ];
        });
    }

    /**
     * Store vector in Pinecone with metadata
     */
    public function storeVector(
        string $vectorId, 
        array $embedding, 
        array $metadata, 
        Tenant $tenant
    ): array {
        $namespace = $this->getTenantNamespace($tenant);
        $indexUrl = $this->getPineconeIndexUrl($tenant);
        
        // Prepare vector data
        $vectorData = [
            'id' => $vectorId,
            'values' => $embedding,
            'metadata' => $this->sanitizeMetadata($metadata)
        ];
        
        // Store in Pinecone
        $response = Http::withHeaders([
            'Api-Key' => $this->getPineconeKey($tenant),
            'Content-Type' => 'application/json'
        ])
        ->timeout($this->config['api']['timeout'])
        ->retry($this->config['api']['retry_attempts'])
        ->post("{$indexUrl}/vectors/upsert", [
            'vectors' => [$vectorData],
            'namespace' => $namespace
        ]);
        
        if (!$response->successful()) {
            Log::error('Pinecone vector storage failed', [
                'status' => $response->status(),
                'response' => $response->body(),
                'vector_id' => $vectorId,
                'tenant_id' => $tenant->id
            ]);
            
            throw new Exception("Failed to store vector: {$response->status()}");
        }
        
        // Store reference in database
        $this->storeVectorReference($vectorId, $embedding, $metadata, $tenant);
        
        return [
            'vector_id' => $vectorId,
            'stored' => true,
            'namespace' => $namespace,
            'dimensions' => count($embedding)
        ];
    }

    /**
     * Batch store multiple vectors efficiently
     */
    public function batchStoreVectors(array $vectors, Tenant $tenant): array
    {
        $namespace = $this->getTenantNamespace($tenant);
        $indexUrl = $this->getPineconeIndexUrl($tenant);
        $batchSize = $this->config['indexing']['batch_size'];
        
        $results = [];
        $batches = array_chunk($vectors, $batchSize);
        
        foreach ($batches as $batchIndex => $batch) {
            try {
                // Prepare batch data
                $vectorData = array_map(function($vector) {
                    return [
                        'id' => $vector['id'],
                        'values' => $vector['embedding'],
                        'metadata' => $this->sanitizeMetadata($vector['metadata'])
                    ];
                }, $batch);
                
                // Batch upsert to Pinecone
                $response = Http::withHeaders([
                    'Api-Key' => $this->getPineconeKey($tenant),
                    'Content-Type' => 'application/json'
                ])
                ->timeout($this->config['api']['timeout'] * 2) // Longer timeout for batches
                ->retry($this->config['api']['retry_attempts'])
                ->post("{$indexUrl}/vectors/upsert", [
                    'vectors' => $vectorData,
                    'namespace' => $namespace
                ]);
                
                if ($response->successful()) {
                    // Store database references
                    foreach ($batch as $vector) {
                        $this->storeVectorReference(
                            $vector['id'], 
                            $vector['embedding'], 
                            $vector['metadata'], 
                            $tenant
                        );
                    }
                    
                    $results[] = [
                        'batch' => $batchIndex,
                        'count' => count($batch),
                        'status' => 'success'
                    ];
                } else {
                    Log::error('Pinecone batch storage failed', [
                        'batch' => $batchIndex,
                        'status' => $response->status(),
                        'response' => $response->body()
                    ]);
                    
                    $results[] = [
                        'batch' => $batchIndex,
                        'count' => count($batch),
                        'status' => 'failed',
                        'error' => $response->body()
                    ];
                }
                
            } catch (Exception $e) {
                Log::error('Batch vector storage exception', [
                    'batch' => $batchIndex,
                    'error' => $e->getMessage()
                ]);
                
                $results[] = [
                    'batch' => $batchIndex,
                    'count' => count($batch),
                    'status' => 'exception',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return [
            'total_vectors' => count($vectors),
            'total_batches' => count($batches),
            'batch_results' => $results,
            'success_rate' => count(array_filter($results, fn($r) => $r['status'] === 'success')) / count($results)
        ];
    }

    /**
     * Semantic search across meeting content
     */
    public function semanticSearch(
        string $query, 
        Tenant $tenant, 
        array $options = []
    ): array {
        $topK = $options['top_k'] ?? $this->config['search']['default_top_k'];
        $threshold = $options['similarity_threshold'] ?? $this->config['search']['similarity_threshold'];
        $filters = $options['filters'] ?? [];
        $includeContext = $options['include_context'] ?? true;
        
        // Generate query embedding
        $queryEmbedding = $this->generateEmbedding($query, $tenant);
        
        // Search Pinecone
        $searchResults = $this->searchPinecone(
            $queryEmbedding['embedding'],
            $tenant,
            $topK,
            $filters
        );
        
        // Filter by similarity threshold
        $filteredResults = array_filter($searchResults['matches'], function($match) use ($threshold) {
            return $match['score'] >= $threshold;
        });
        
        // Enhance results with context and insights
        $enhancedResults = $this->enhanceSearchResults($filteredResults, $tenant, $includeContext);
        
        return [
            'query' => $query,
            'total_matches' => count($filteredResults),
            'search_time_ms' => $searchResults['search_time_ms'],
            'results' => $enhancedResults,
            'semantic_insights' => $this->generateSearchInsights($query, $enhancedResults, $tenant)
        ];
    }

    /**
     * Find similar meetings and content
     */
    public function findSimilarContent(
        string $vectorId, 
        Tenant $tenant, 
        array $options = []
    ): array {
        $topK = $options['top_k'] ?? 5;
        $excludeSelf = $options['exclude_self'] ?? true;
        
        // Get vector from database
        $vectorRecord = VectorEmbedding::where('vector_id', $vectorId)
            ->where('tenant_id', $tenant->id)
            ->first();
        
        if (!$vectorRecord) {
            throw new Exception("Vector not found: {$vectorId}");
        }
        
        // Get embedding from Pinecone
        $vectorData = $this->getVectorFromPinecone($vectorId, $tenant);
        
        if (!$vectorData) {
            throw new Exception("Vector not found in Pinecone: {$vectorId}");
        }
        
        // Search for similar vectors
        $searchResults = $this->searchPinecone(
            $vectorData['values'],
            $tenant,
            $excludeSelf ? $topK + 1 : $topK
        );
        
        // Filter out self if requested
        $filteredMatches = $searchResults['matches'];
        if ($excludeSelf) {
            $filteredMatches = array_filter($filteredMatches, function($match) use ($vectorId) {
                return $match['id'] !== $vectorId;
            });
            $filteredMatches = array_slice($filteredMatches, 0, $topK);
        }
        
        // Enhance with context
        $enhancedResults = $this->enhanceSearchResults($filteredMatches, $tenant, true);
        
        return [
            'source_vector_id' => $vectorId,
            'source_content' => $vectorRecord->content,
            'similar_content' => $enhancedResults,
            'similarity_analysis' => $this->analyzeSimilarityPatterns($enhancedResults)
        ];
    }

    /**
     * Advanced search with multiple query strategies
     */
    public function advancedSearch(array $searchParams, Tenant $tenant): array
    {
        $strategies = $searchParams['strategies'] ?? ['semantic', 'keyword', 'hybrid'];
        $query = $searchParams['query'];
        $results = [];
        
        foreach ($strategies as $strategy) {
            switch ($strategy) {
                case 'semantic':
                    $results['semantic'] = $this->semanticSearch($query, $tenant, $searchParams);
                    break;
                    
                case 'keyword':
                    $results['keyword'] = $this->keywordSearch($query, $tenant, $searchParams);
                    break;
                    
                case 'hybrid':
                    $results['hybrid'] = $this->hybridSearch($query, $tenant, $searchParams);
                    break;
                    
                case 'temporal':
                    $results['temporal'] = $this->temporalSearch($query, $tenant, $searchParams);
                    break;
            }
        }
        
        // Combine and rank results
        $combinedResults = $this->combineSearchResults($results, $searchParams);
        
        return [
            'query' => $query,
            'strategies_used' => $strategies,
            'individual_results' => $results,
            'combined_results' => $combinedResults,
            'search_performance' => $this->calculateSearchPerformance($results)
        ];
    }

    /**
     * Generate insights from meeting transcripts using vector similarity
     */
    public function generateMeetingInsights(string $meetingId, Tenant $tenant): array
    {
        // Find all vectors for this meeting
        $meetingVectors = VectorEmbedding::where('tenant_id', $tenant->id)
            ->where('source_type', 'transcript')
            ->whereJsonContains('metadata->meeting_id', $meetingId)
            ->get();
        
        if ($meetingVectors->isEmpty()) {
            return ['insights' => [], 'message' => 'No vectors found for meeting'];
        }
        
        $insights = [];
        
        // Cross-reference insights
        foreach ($meetingVectors as $vector) {
            // Find similar content across all meetings
            $similarContent = $this->findSimilarContent($vector->vector_id, $tenant, [
                'top_k' => 5,
                'exclude_self' => true
            ]);
            
            // Analyze patterns and generate insights
            $insights[] = [
                'vector_id' => $vector->vector_id,
                'content_snippet' => substr($vector->content, 0, 200),
                'similar_meetings' => $this->extractMeetingReferences($similarContent),
                'topic_analysis' => $this->analyzeTopicPatterns($similarContent),
                'trend_insights' => $this->identifyTrends($similarContent)
            ];
        }
        
        return [
            'meeting_id' => $meetingId,
            'vectors_analyzed' => count($meetingVectors),
            'insights' => $insights,
            'summary_insights' => $this->summarizeMeetingInsights($insights),
            'generated_at' => Carbon::now()->toISOString()
        ];
    }

    /**
     * Search Pinecone index
     */
    private function searchPinecone(
        array $queryVector, 
        Tenant $tenant, 
        int $topK, 
        array $filters = []
    ): array {
        $startTime = microtime(true);
        $namespace = $this->getTenantNamespace($tenant);
        $indexUrl = $this->getPineconeIndexUrl($tenant);
        
        $requestBody = [
            'vector' => $queryVector,
            'topK' => min($topK, $this->config['search']['max_top_k']),
            'namespace' => $namespace,
            'includeMetadata' => $this->config['search']['include_metadata'],
            'includeValues' => $this->config['search']['include_values']
        ];
        
        // Add metadata filters if provided
        if (!empty($filters)) {
            $requestBody['filter'] = $this->buildPineconeFilter($filters);
        }
        
        $response = Http::withHeaders([
            'Api-Key' => $this->getPineconeKey($tenant),
            'Content-Type' => 'application/json'
        ])
        ->timeout($this->config['api']['timeout'])
        ->retry($this->config['api']['retry_attempts'])
        ->post("{$indexUrl}/query", $requestBody);
        
        if (!$response->successful()) {
            Log::error('Pinecone search failed', [
                'status' => $response->status(),
                'response' => $response->body(),
                'tenant_id' => $tenant->id
            ]);
            
            throw new Exception("Pinecone search failed: {$response->status()}");
        }
        
        $data = $response->json();
        $searchTime = round((microtime(true) - $startTime) * 1000, 2);
        
        return [
            'matches' => $data['matches'] ?? [],
            'search_time_ms' => $searchTime,
            'namespace' => $namespace
        ];
    }

    /**
     * Enhance search results with additional context and insights
     */
    private function enhanceSearchResults(array $matches, Tenant $tenant, bool $includeContext): array
    {
        $enhancedResults = [];
        
        foreach ($matches as $match) {
            $vectorId = $match['id'];
            $score = $match['score'];
            $metadata = $match['metadata'] ?? [];
            
            // Get vector record from database
            $vectorRecord = VectorEmbedding::where('vector_id', $vectorId)
                ->where('tenant_id', $tenant->id)
                ->first();
            
            $result = [
                'vector_id' => $vectorId,
                'similarity_score' => $score,
                'content' => $vectorRecord?->content ?? 'Content not found',
                'source_type' => $metadata['content_type'] ?? 'unknown',
                'metadata' => $metadata
            ];
            
            if ($includeContext && $vectorRecord) {
                $result['context'] = $this->getContentContext($vectorRecord, $tenant);
            }
            
            // Add AI-generated insights
            $result['ai_insights'] = $this->generateContentInsights($result['content'], $score, $tenant);
            
            $enhancedResults[] = $result;
        }
        
        return $enhancedResults;
    }

    // Helper methods for data processing and API integration
    
    private function getTenantNamespace(Tenant $tenant): string
    {
        return "tenant_{$tenant->id}";
    }
    
    private function getPineconeIndexUrl(Tenant $tenant): string
    {
        $pineconeConfig = json_decode($tenant->integration_settings, true)['pinecone'] ?? [];
        $environment = $pineconeConfig['environment'] ?? 'us-east1-gcp';
        $indexName = $pineconeConfig['index_name'] ?? 'ai-project-management';
        
        return "https://{$indexName}-{$environment}.svc.pinecone.io";
    }
    
    private function getPineconeKey(Tenant $tenant): string
    {
        return $tenant->pinecone_api_key ?? throw new Exception("Pinecone API key not configured");
    }
    
    private function getOpenAIKey(Tenant $tenant): string
    {
        return $tenant->openai_api_key ?? config('services.openai.key') ?? throw new Exception("OpenAI API key not configured");
    }
    
    private function truncateToTokenLimit(string $content): string
    {
        // Rough estimation: 1 token ≈ 4 characters
        $maxChars = self::MAX_TOKENS_PER_REQUEST * 3; // Conservative estimate
        
        if (strlen($content) > $maxChars) {
            return substr($content, 0, $maxChars - 100) . '...'; // Leave buffer
        }
        
        return $content;
    }
    
    private function sanitizeMetadata(array $metadata): array
    {
        // Ensure metadata is within Pinecone limits
        $sanitized = [];
        $totalSize = 0;
        
        foreach ($metadata as $key => $value) {
            if (is_string($value) && strlen($value) > 500) {
                $value = substr($value, 0, 500);
            }
            
            $entrySize = strlen(json_encode([$key => $value]));
            
            if ($totalSize + $entrySize < $this->config['indexing']['max_metadata_size']) {
                $sanitized[$key] = $value;
                $totalSize += $entrySize;
            }
        }
        
        return $sanitized;
    }
    
    private function storeVectorReference(
        string $vectorId, 
        array $embedding, 
        array $metadata, 
        Tenant $tenant
    ): void {
        VectorEmbedding::updateOrCreate(
            ['vector_id' => $vectorId, 'tenant_id' => $tenant->id],
            [
                'source_type' => $metadata['content_type'] ?? 'unknown',
                'source_id' => $metadata['source_id'] ?? null,
                'content' => $metadata['content'] ?? '',
                'metadata' => json_encode($metadata),
                'dimensions' => count($embedding),
                'embedding_model' => self::EMBEDDING_MODEL,
                'synced_to_pinecone' => true,
                'pinecone_synced_at' => Carbon::now(),
                'pinecone_namespace' => $this->getTenantNamespace($tenant)
            ]
        );
    }
    
    private function getVectorFromPinecone(string $vectorId, Tenant $tenant): ?array
    {
        $namespace = $this->getTenantNamespace($tenant);
        $indexUrl = $this->getPineconeIndexUrl($tenant);
        
        $response = Http::withHeaders([
            'Api-Key' => $this->getPineconeKey($tenant),
            'Content-Type' => 'application/json'
        ])
        ->timeout($this->config['api']['timeout'])
        ->get("{$indexUrl}/vectors/fetch", [
            'ids' => [$vectorId],
            'namespace' => $namespace
        ]);
        
        if ($response->successful()) {
            $data = $response->json();
            return $data['vectors'][$vectorId] ?? null;
        }
        
        return null;
    }
    
    private function buildPineconeFilter(array $filters): array
    {
        // Convert application filters to Pinecone filter format
        $pineconeFilter = [];
        
        foreach ($filters as $key => $value) {
            if (is_array($value)) {
                $pineconeFilter[$key] = ['$in' => $value];
            } else {
                $pineconeFilter[$key] = ['$eq' => $value];
            }
        }
        
        return $pineconeFilter;
    }
    
    // Simplified implementations for search and analysis methods
    
    private function keywordSearch(string $query, Tenant $tenant, array $options): array
    {
        // Simplified keyword search implementation
        return [
            'query' => $query,
            'results' => [],
            'strategy' => 'keyword'
        ];
    }
    
    private function hybridSearch(string $query, Tenant $tenant, array $options): array
    {
        // Simplified hybrid search implementation
        return [
            'query' => $query,
            'results' => [],
            'strategy' => 'hybrid'
        ];
    }
    
    private function temporalSearch(string $query, Tenant $tenant, array $options): array
    {
        // Simplified temporal search implementation
        return [
            'query' => $query,
            'results' => [],
            'strategy' => 'temporal'
        ];
    }
    
    private function combineSearchResults(array $results, array $params): array
    {
        return [
            'combined_results' => [],
            'ranking_strategy' => 'weighted_average',
            'total_unique_results' => 0
        ];
    }
    
    private function calculateSearchPerformance(array $results): array
    {
        return [
            'total_search_time' => 0,
            'average_similarity' => 0,
            'result_diversity' => 0
        ];
    }
    
    private function getContentContext(VectorEmbedding $vector, Tenant $tenant): array
    {
        return [
            'source_meeting' => 'Meeting context',
            'timestamp' => 'Time context',
            'speakers' => ['Speaker context']
        ];
    }
    
    private function generateContentInsights(string $content, float $score, Tenant $tenant): array
    {
        return [
            'relevance' => $score > 0.8 ? 'high' : ($score > 0.6 ? 'medium' : 'low'),
            'key_topics' => ['topic1', 'topic2'],
            'sentiment' => 'neutral'
        ];
    }
    
    private function generateSearchInsights(string $query, array $results, Tenant $tenant): array
    {
        return [
            'query_type' => 'informational',
            'result_quality' => 'good',
            'suggested_refinements' => []
        ];
    }
    
    private function extractMeetingReferences(array $similarContent): array
    {
        return [
            'similar_meetings' => [],
            'common_topics' => [],
            'recurring_participants' => []
        ];
    }
    
    private function analyzeTopicPatterns(array $similarContent): array
    {
        return [
            'dominant_topics' => [],
            'topic_evolution' => [],
            'topic_sentiment' => []
        ];
    }
    
    private function identifyTrends(array $similarContent): array
    {
        return [
            'temporal_trends' => [],
            'sentiment_trends' => [],
            'participation_trends' => []
        ];
    }
    
    private function summarizeMeetingInsights(array $insights): array
    {
        return [
            'key_themes' => [],
            'action_items_patterns' => [],
            'decision_making_insights' => [],
            'collaboration_patterns' => []
        ];
    }
    
    private function analyzeSimilarityPatterns(array $results): array
    {
        return [
            'similarity_distribution' => [],
            'content_clusters' => [],
            'outlier_analysis' => []
        ];
    }
}