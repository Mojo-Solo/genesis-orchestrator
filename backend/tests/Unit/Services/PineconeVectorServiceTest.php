<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\PineconeVectorService;
use App\Models\Tenant;
use App\Models\Meeting;
use App\Models\MeetingTranscript;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Mockery;

/**
 * @group unit
 * @group services
 * @group vector
 */
class PineconeVectorServiceTest extends TestCase
{
    protected PineconeVectorService $service;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->service = new PineconeVectorService();
        $this->tenant = $this->defaultTenant;
        
        // Mock HTTP responses
        $this->mockPineconeAPI();
    }

    private function mockPineconeAPI(): void
    {
        Http::fake([
            'https://test-pinecone.svc.environment.pinecone.io/vectors/upsert' => Http::response([
                'upsertedCount' => 1
            ], 200),
            
            'https://test-pinecone.svc.environment.pinecone.io/query' => Http::response([
                'matches' => [
                    [
                        'id' => 'test_vector_1',
                        'score' => 0.95,
                        'values' => array_fill(0, 1536, 0.5),
                        'metadata' => [
                            'content' => 'Test content',
                            'tenant_id' => $this->tenant->id
                        ]
                    ]
                ]
            ], 200),
            
            'https://test-pinecone.svc.environment.pinecone.io/vectors/delete' => Http::response([], 200),
            
            'https://test-pinecone.svc.environment.pinecone.io/describe_index_stats' => Http::response([
                'dimension' => 1536,
                'indexFullness' => 0.1,
                'totalVectorCount' => 100,
                'namespaces' => [
                    "tenant_{$this->tenant->id}" => [
                        'vectorCount' => 50
                    ]
                ]
            ], 200)
        ]);
    }

    /** @test */
    public function it_can_initialize_tenant_namespace()
    {
        $result = $this->service->initializeTenantNamespace($this->tenant);

        $this->assertTrue($result['namespace_created']);
        $this->assertEquals("tenant_{$this->tenant->id}", $result['namespace']);
        $this->assertArrayHasKey('index_stats', $result);
    }

    /** @test */
    public function it_can_generate_embeddings()
    {
        $content = 'This is test content for embedding generation';
        
        $result = $this->service->generateEmbedding($content, $this->tenant);

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['embedding']);
        $this->assertCount(1536, $result['embedding']); // OpenAI embedding dimension
        $this->assertArrayHasKey('token_count', $result);
        $this->assertArrayHasKey('processing_time_ms', $result);
    }

    /** @test */
    public function it_caches_embeddings_for_duplicate_content()
    {
        $content = 'Duplicate content for caching test';
        
        // First call should generate and cache
        $result1 = $this->service->generateEmbedding($content, $this->tenant);
        
        // Second call should use cache
        $result2 = $this->service->generateEmbedding($content, $this->tenant);

        $this->assertTrue($result1['success']);
        $this->assertTrue($result2['success']);
        $this->assertEquals($result1['embedding'], $result2['embedding']);
        $this->assertTrue($result2['cached']); // Should indicate cached result
    }

    /** @test */
    public function it_can_store_vectors_with_metadata()
    {
        $vectors = [
            [
                'id' => 'test_vector_1',
                'values' => array_fill(0, 1536, 0.5),
                'metadata' => [
                    'content' => 'Test content',
                    'type' => 'meeting_transcript',
                    'meeting_id' => 123
                ]
            ],
            [
                'id' => 'test_vector_2',
                'values' => array_fill(0, 1536, 0.3),
                'metadata' => [
                    'content' => 'Another test content',
                    'type' => 'action_item',
                    'priority' => 'high'
                ]
            ]
        ];

        $result = $this->service->upsertVectors($vectors, $this->tenant);

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['upserted_count']);
        $this->assertArrayHasKey('processing_time_ms', $result);
    }

    /** @test */
    public function it_can_perform_semantic_search()
    {
        $query = 'Find discussions about project deadlines';
        $options = [
            'top_k' => 5,
            'include_metadata' => true,
            'filter' => ['type' => 'meeting_transcript']
        ];

        $result = $this->service->semanticSearch($query, $this->tenant, $options);

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['matches']);
        $this->assertArrayHasKey('query_embedding', $result);
        $this->assertArrayHasKey('processing_time_ms', $result);
        
        if (!empty($result['matches'])) {
            $match = $result['matches'][0];
            $this->assertArrayHasKey('id', $match);
            $this->assertArrayHasKey('score', $match);
            $this->assertArrayHasKey('metadata', $match);
            $this->assertIsFloat($match['score']);
            $this->assertGreaterThanOrEqual(0, $match['score']);
            $this->assertLessThanOrEqual(1, $match['score']);
        }
    }

    /** @test */
    public function it_can_search_with_hybrid_approach()
    {
        $query = 'Project status and timeline discussions';
        $options = [
            'hybrid_search' => true,
            'keyword_weight' => 0.3,
            'semantic_weight' => 0.7,
            'top_k' => 10
        ];

        $result = $this->service->hybridSearch($query, $this->tenant, $options);

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['matches']);
        $this->assertEquals('hybrid', $result['search_type']);
        $this->assertArrayHasKey('keyword_results', $result);
        $this->assertArrayHasKey('semantic_results', $result);
        $this->assertArrayHasKey('combined_score', $result);
    }

    /** @test */
    public function it_enforces_tenant_isolation()
    {
        $otherTenant = Tenant::factory()->create();
        
        // Store vector for original tenant
        $vectors = [
            [
                'id' => 'tenant_isolated_vector',
                'values' => array_fill(0, 1536, 0.5),
                'metadata' => ['content' => 'Tenant specific content']
            ]
        ];
        
        $this->service->upsertVectors($vectors, $this->tenant);

        // Search from other tenant should not find the vector
        $result = $this->service->semanticSearch('Tenant specific content', $otherTenant);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['matches']); // Should not find vectors from other tenant
    }

    /** @test */
    public function it_can_delete_vectors()
    {
        $vectorIds = ['vector_1', 'vector_2', 'vector_3'];

        $result = $this->service->deleteVectors($vectorIds, $this->tenant);

        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['deleted_count']);
        $this->assertArrayHasKey('processing_time_ms', $result);
    }

    /** @test */
    public function it_can_bulk_process_meeting_transcripts()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->tenant->id
        ]);

        $transcripts = [
            MeetingTranscript::factory()->make([
                'meeting_id' => $meeting->id,
                'content' => 'First part of the meeting discussion about project planning',
                'sentences' => json_encode([
                    ['speaker' => 'John', 'text' => 'Let\'s discuss project planning'],
                    ['speaker' => 'Jane', 'text' => 'We need to review the timeline']
                ])
            ]),
            MeetingTranscript::factory()->make([
                'meeting_id' => $meeting->id,
                'content' => 'Second part discussing resource allocation and deadlines',
                'sentences' => json_encode([
                    ['speaker' => 'Bob', 'text' => 'Resource allocation is critical'],
                    ['speaker' => 'Alice', 'text' => 'Deadlines must be realistic']
                ])
            ])
        ];

        $result = $this->service->bulkProcessTranscripts($transcripts->toArray(), $this->tenant);

        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['vectors_created']);
        $this->assertEquals(2, $result['transcripts_processed']);
        $this->assertArrayHasKey('processing_time_ms', $result);
    }

    /** @test */
    public function it_handles_chunking_for_large_content()
    {
        $largeContent = str_repeat('This is a very long meeting transcript. ', 1000);
        
        $result = $this->service->processLargeContent($largeContent, $this->tenant, [
            'chunk_size' => 500,
            'overlap' => 50,
            'content_type' => 'meeting_transcript'
        ]);

        $this->assertTrue($result['success']);
        $this->assertGreaterThan(1, $result['chunks_created']);
        $this->assertArrayHasKey('chunk_details', $result);
        
        foreach ($result['chunk_details'] as $chunk) {
            $this->assertArrayHasKey('id', $chunk);
            $this->assertArrayHasKey('size', $chunk);
            $this->assertArrayHasKey('overlap_size', $chunk);
        }
    }

    /** @test */
    public function it_can_find_similar_meetings()
    {
        $targetMeeting = Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Weekly Product Planning Meeting'
        ]);

        $result = $this->service->findSimilarMeetings($targetMeeting, $this->tenant, [
            'similarity_threshold' => 0.7,
            'max_results' => 10,
            'exclude_same_meeting' => true
        ]);

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['similar_meetings']);
        $this->assertArrayHasKey('similarity_scores', $result);
        
        if (!empty($result['similar_meetings'])) {
            foreach ($result['similar_meetings'] as $similar) {
                $this->assertArrayHasKey('meeting_id', $similar);
                $this->assertArrayHasKey('similarity_score', $similar);
                $this->assertArrayHasKey('matching_topics', $similar);
                $this->assertNotEquals($targetMeeting->id, $similar['meeting_id']);
            }
        }
    }

    /** @test */
    public function it_can_extract_topics_and_themes()
    {
        $content = 'We discussed project planning, resource allocation, timeline management, and risk assessment for the upcoming product launch.';

        $result = $this->service->extractTopicsAndThemes($content, $this->tenant);

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['topics']);
        $this->assertIsArray($result['themes']);
        $this->assertArrayHasKey('confidence_scores', $result);
        
        // Should extract relevant topics
        $topics = array_column($result['topics'], 'topic');
        $this->assertContains('project planning', $topics);
        $this->assertContains('resource allocation', $topics);
    }

    /** @test */
    public function it_handles_api_rate_limiting()
    {
        // Mock rate limit response
        Http::fake([
            'https://test-pinecone.svc.environment.pinecone.io/*' => Http::response([], 429)
        ]);

        $result = $this->service->generateEmbedding('Test content', $this->tenant);

        $this->assertFalse($result['success']);
        $this->assertEquals('rate_limited', $result['error_type']);
        $this->assertArrayHasKey('retry_after', $result);
    }

    /** @test */
    public function it_handles_api_errors_gracefully()
    {
        // Mock API error response
        Http::fake([
            'https://test-pinecone.svc.environment.pinecone.io/*' => Http::response([
                'error' => 'Invalid request format'
            ], 400)
        ]);

        $result = $this->service->semanticSearch('test query', $this->tenant);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('api_error', $result['error_type']);
    }

    /** @test */
    public function it_validates_vector_dimensions()
    {
        $invalidVectors = [
            [
                'id' => 'invalid_vector',
                'values' => array_fill(0, 512, 0.5), // Wrong dimension
                'metadata' => ['content' => 'Test content']
            ]
        ];

        $result = $this->service->upsertVectors($invalidVectors, $this->tenant);

        $this->assertFalse($result['success']);
        $this->assertEquals('invalid_dimensions', $result['error_type']);
        $this->assertStringContains('dimension', $result['error']);
    }

    /** @test */
    public function it_can_get_index_statistics()
    {
        $stats = $this->service->getIndexStatistics($this->tenant);

        $this->assertTrue($stats['success']);
        $this->assertArrayHasKey('total_vectors', $stats);
        $this->assertArrayHasKey('namespace_vectors', $stats);
        $this->assertArrayHasKey('index_fullness', $stats);
        $this->assertArrayHasKey('dimension', $stats);
    }

    /** @test */
    public function it_measures_performance_correctly()
    {
        $content = 'Performance test content';

        // Test embedding generation performance
        $start = microtime(true);
        $result = $this->service->generateEmbedding($content, $this->tenant);
        $duration = microtime(true) - $start;

        $this->assertLessThan(2.0, $duration); // Should complete within 2 seconds
        $this->assertArrayHasKey('processing_time_ms', $result);
        $this->assertIsFloat($result['processing_time_ms']);
    }

    /** @test */
    public function it_handles_empty_and_invalid_content()
    {
        // Test empty content
        $emptyResult = $this->service->generateEmbedding('', $this->tenant);
        $this->assertFalse($emptyResult['success']);
        $this->assertEquals('empty_content', $emptyResult['error_type']);

        // Test very long content
        $longContent = str_repeat('x', 100000);
        $longResult = $this->service->generateEmbedding($longContent, $this->tenant);
        $this->assertFalse($longResult['success']);
        $this->assertEquals('content_too_long', $longResult['error_type']);

        // Test null content
        $nullResult = $this->service->generateEmbedding(null, $this->tenant);
        $this->assertFalse($nullResult['success']);
        $this->assertEquals('invalid_content', $nullResult['error_type']);
    }

    /** @test */
    public function it_can_cleanup_old_vectors()
    {
        $cutoffDate = now()->subDays(30);

        $result = $this->service->cleanupOldVectors($this->tenant, $cutoffDate);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('deleted_count', $result);
        $this->assertArrayHasKey('cleanup_date', $result);
        $this->assertIsInt($result['deleted_count']);
    }

    /** @test */
    public function it_supports_metadata_filtering()
    {
        $filters = [
            'meeting_type' => 'standup',
            'date' => ['gte' => '2024-01-01'],
            'participants' => ['in' => ['john@example.com', 'jane@example.com']]
        ];

        $result = $this->service->semanticSearch('project updates', $this->tenant, [
            'filter' => $filters,
            'top_k' => 20
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('filters_applied', $result);
        $this->assertEquals($filters, $result['filters_applied']);
    }
}