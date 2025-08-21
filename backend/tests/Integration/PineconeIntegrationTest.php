<?php

namespace Tests\Integration;

use Tests\TestCase;
use App\Services\PineconeVectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Models\Meeting;
use App\Models\Transcript;
use App\Models\User;
use App\Models\Tenant;
use App\Models\VectorIndex;

/**
 * Integration Tests for Pinecone Vector Database
 * 
 * Tests the complete integration with Pinecone including:
 * - Index creation and management
 * - Vector embedding generation and storage
 * - Semantic search and similarity matching
 * - Multi-tenant vector isolation
 * - Performance optimization and caching
 * - Error handling and recovery
 * - Batch operations and scaling
 */
class PineconeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected PineconeVectorService $pineconeService;
    protected User $user;
    protected Tenant $tenant;
    protected Meeting $meeting;
    protected Transcript $transcript;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'name' => 'Test Tenant',
            'tier' => 'professional',
            'settings' => [
                'pinecone_api_key' => 'test_pinecone_key_123',
                'pinecone_environment' => 'test-env',
                'pinecone_enabled' => true,
            ],
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->meeting = Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'title' => 'Vector Test Meeting',
        ]);

        $this->transcript = Transcript::factory()->create([
            'meeting_id' => $this->meeting->id,
            'content' => 'This is a test transcript about project management and team collaboration.',
            'sentences' => [
                [
                    'speaker' => 'John Doe',
                    'text' => 'Let\'s discuss the project timeline and deliverables.',
                    'timestamp' => '00:01:00',
                ],
                [
                    'speaker' => 'Jane Smith',
                    'text' => 'We need to prioritize the user interface design.',
                    'timestamp' => '00:02:30',
                ],
            ],
        ]);

        $this->pineconeService = new PineconeVectorService();
        $this->actingAs($this->user);
    }

    /** @test */
    public function it_can_create_and_configure_vector_index()
    {
        Http::fake([
            'controller.test-env.pinecone.io/databases' => Http::response([
                'name' => 'tenant-' . $this->tenant->id,
                'dimension' => 1536,
                'metric' => 'cosine',
                'status' => [
                    'ready' => true,
                    'state' => 'Ready',
                ],
            ], 201),
            'controller.test-env.pinecone.io/databases/*' => Http::response([
                'database' => [
                    'name' => 'tenant-' . $this->tenant->id,
                    'dimension' => 1536,
                    'metric' => 'cosine',
                    'status' => ['ready' => true],
                ],
            ], 200),
        ]);

        $result = $this->pineconeService->createIndex($this->tenant);

        $this->assertTrue($result['success']);
        $this->assertEquals('tenant-' . $this->tenant->id, $result['index_name']);
        $this->assertEquals(1536, $result['dimension']);

        // Verify index was recorded in database
        $this->assertDatabaseHas('vector_indexes', [
            'tenant_id' => $this->tenant->id,
            'name' => 'tenant-' . $this->tenant->id,
            'status' => 'ready',
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/databases') &&
                   $request->hasHeader('Api-Key', 'test_pinecone_key_123') &&
                   $request->method() === 'POST';
        });
    }

    /** @test */
    public function it_handles_index_creation_failures()
    {
        Http::fake([
            'controller.test-env.pinecone.io/databases' => Http::response([
                'error' => [
                    'code' => 'QUOTA_EXCEEDED',
                    'message' => 'Index quota exceeded for this project',
                ],
            ], 400),
        ]);

        $result = $this->pineconeService->createIndex($this->tenant);

        $this->assertFalse($result['success']);
        $this->assertStringContains('quota exceeded', strtolower($result['error']));
    }

    /** @test */
    public function it_can_generate_and_store_embeddings()
    {
        // Mock OpenAI embedding response
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [
                    [
                        'embedding' => array_fill(0, 1536, 0.1),
                        'index' => 0,
                    ],
                    [
                        'embedding' => array_fill(0, 1536, 0.2),
                        'index' => 1,
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 50,
                    'total_tokens' => 50,
                ],
            ], 200),
            // Mock Pinecone upsert response
            'tenant-*.pinecone.io/vectors/upsert' => Http::response([
                'upsertedCount' => 2,
            ], 200),
        ]);

        $texts = [
            'Project management discussion with timeline details',
            'User interface design priorities and requirements',
        ];

        $result = $this->pineconeService->generateAndStoreEmbeddings(
            $this->tenant,
            $texts,
            ['meeting_id' => $this->meeting->id]
        );

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['stored_count']);
        $this->assertArrayHasKey('vector_ids', $result);

        // Verify vectors were stored with proper metadata
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            return str_contains($request->url(), '/vectors/upsert') &&
                   count($body['vectors']) === 2 &&
                   isset($body['vectors'][0]['metadata']['meeting_id']);
        });
    }

    /** @test */
    public function it_can_perform_semantic_search()
    {
        // Mock embedding generation for search query
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [
                    [
                        'embedding' => array_fill(0, 1536, 0.15),
                        'index' => 0,
                    ],
                ],
            ], 200),
            // Mock Pinecone query response
            'tenant-*.pinecone.io/query' => Http::response([
                'matches' => [
                    [
                        'id' => 'vec_123',
                        'score' => 0.95,
                        'metadata' => [
                            'meeting_id' => $this->meeting->id,
                            'text' => 'Project management discussion',
                            'speaker' => 'John Doe',
                            'timestamp' => '00:01:00',
                        ],
                    ],
                    [
                        'id' => 'vec_124',
                        'score' => 0.87,
                        'metadata' => [
                            'meeting_id' => $this->meeting->id,
                            'text' => 'User interface design priorities',
                            'speaker' => 'Jane Smith',
                            'timestamp' => '00:02:30',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $searchResults = $this->pineconeService->semanticSearch(
            $this->tenant,
            'project timeline and deliverables',
            ['meeting_id' => $this->meeting->id],
            5
        );

        $this->assertTrue($searchResults['success']);
        $this->assertCount(2, $searchResults['matches']);
        $this->assertEquals(0.95, $searchResults['matches'][0]['score']);
        $this->assertEquals($this->meeting->id, $searchResults['matches'][0]['metadata']['meeting_id']);

        // Verify proper search query was sent
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            return str_contains($request->url(), '/query') &&
                   isset($body['vector']) &&
                   count($body['vector']) === 1536 &&
                   $body['topK'] === 5;
        });
    }

    /** @test */
    public function it_enforces_multi_tenant_isolation()
    {
        // Create second tenant
        $tenant2 = Tenant::factory()->create([
            'name' => 'Second Tenant',
            'settings' => [
                'pinecone_api_key' => 'different_key_456',
                'pinecone_environment' => 'test-env',
            ],
        ]);

        $meeting2 = Meeting::factory()->create([
            'tenant_id' => $tenant2->id,
        ]);

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => array_fill(0, 1536, 0.1)]],
            ], 200),
            'tenant-*.pinecone.io/query' => Http::response([
                'matches' => [
                    [
                        'id' => 'vec_123',
                        'score' => 0.95,
                        'metadata' => [
                            'meeting_id' => $this->meeting->id,
                            'tenant_id' => $this->tenant->id,
                        ],
                    ],
                ],
            ], 200),
        ]);

        // Search from first tenant should only return their data
        $results1 = $this->pineconeService->semanticSearch(
            $this->tenant,
            'test query',
            ['tenant_id' => $this->tenant->id]
        );

        // Search from second tenant should be isolated
        $results2 = $this->pineconeService->semanticSearch(
            $tenant2,
            'test query',
            ['tenant_id' => $tenant2->id]
        );

        $this->assertTrue($results1['success']);
        $this->assertEquals($this->tenant->id, $results1['matches'][0]['metadata']['tenant_id']);

        // Verify different API keys were used
        Http::assertSent(function ($request) {
            return $request->hasHeader('Api-Key', 'test_pinecone_key_123');
        });

        Http::assertSent(function ($request) {
            return $request->hasHeader('Api-Key', 'different_key_456');
        });
    }

    /** @test */
    public function it_handles_batch_operations_efficiently()
    {
        // Create large batch of texts to process
        $texts = [];
        $expectedVectors = [];
        for ($i = 0; $i < 100; $i++) {
            $texts[] = "Meeting content batch item {$i} with relevant information.";
            $expectedVectors[] = [
                'embedding' => array_fill(0, 1536, $i * 0.01),
                'index' => $i,
            ];
        }

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => $expectedVectors,
                'usage' => [
                    'prompt_tokens' => 2000,
                    'total_tokens' => 2000,
                ],
            ], 200),
            'tenant-*.pinecone.io/vectors/upsert' => Http::response([
                'upsertedCount' => 100,
            ], 200),
        ]);

        $startTime = microtime(true);
        $result = $this->pineconeService->batchProcessEmbeddings(
            $this->tenant,
            $texts,
            ['meeting_id' => $this->meeting->id],
            50 // Batch size
        );
        $processingTime = microtime(true) - $startTime;

        $this->assertTrue($result['success']);
        $this->assertEquals(100, $result['total_processed']);
        $this->assertLessThan(10.0, $processingTime); // Should complete within 10 seconds

        // Verify batching was used (2 batches of 50)
        Http::assertSentCount(3); // 1 embedding call + 2 upsert calls
    }

    /** @test */
    public function it_implements_caching_for_performance()
    {
        Cache::shouldReceive('remember')
            ->once()
            ->with(
                \Mockery::pattern('/embedding_cache_/'),
                3600,
                \Mockery::type('callable')
            )
            ->andReturn(array_fill(0, 1536, 0.1));

        Http::fake([
            'tenant-*.pinecone.io/query' => Http::response([
                'matches' => [
                    [
                        'id' => 'vec_123',
                        'score' => 0.95,
                        'metadata' => ['text' => 'cached result'],
                    ],
                ],
            ], 200),
        ]);

        // First call should generate and cache embedding
        $result1 = $this->pineconeService->semanticSearch(
            $this->tenant,
            'cached query text',
            []
        );

        // Second call with same query should use cached embedding
        $result2 = $this->pineconeService->semanticSearch(
            $this->tenant,
            'cached query text',
            []
        );

        $this->assertTrue($result1['success']);
        $this->assertTrue($result2['success']);

        // Should only have called OpenAI once due to caching
        Http::assertDidntSend(function ($request) {
            return str_contains($request->url(), 'api.openai.com');
        });
    }

    /** @test */
    public function it_handles_vector_updates_and_deletions()
    {
        Http::fake([
            'tenant-*.pinecone.io/vectors/update' => Http::response('', 200),
            'tenant-*.pinecone.io/vectors/delete' => Http::response('', 200),
        ]);

        // Test vector update
        $updateResult = $this->pineconeService->updateVector(
            $this->tenant,
            'vec_123',
            ['updated_text' => 'Updated meeting content']
        );

        $this->assertTrue($updateResult['success']);

        // Test vector deletion
        $deleteResult = $this->pineconeService->deleteVectors(
            $this->tenant,
            ['vec_123', 'vec_124']
        );

        $this->assertTrue($deleteResult['success']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/vectors/update') &&
                   $request->method() === 'POST';
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/vectors/delete') &&
                   $request->method() === 'POST';
        });
    }

    /** @test */
    public function it_handles_api_errors_with_proper_retry_logic()
    {
        // First call fails with rate limit, second succeeds
        Http::fakeSequence()
            ->push([
                'error' => ['message' => 'Rate limit exceeded'],
            ], 429, ['Retry-After' => '1'])
            ->push([
                'data' => [['embedding' => array_fill(0, 1536, 0.1)]],
            ], 200);

        $result = $this->pineconeService->generateEmbedding('test text');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('embedding', $result);

        // Verify retry was attempted
        Http::assertSentCount(2);
    }

    /** @test */
    public function it_monitors_index_statistics_and_health()
    {
        Http::fake([
            'tenant-*.pinecone.io/describe_index_stats' => Http::response([
                'dimension' => 1536,
                'index_fullness' => 0.3,
                'total_vector_count' => 15000,
                'namespaces' => [
                    'default' => [
                        'vector_count' => 15000,
                    ],
                ],
            ], 200),
        ]);

        $stats = $this->pineconeService->getIndexStats($this->tenant);

        $this->assertTrue($stats['success']);
        $this->assertEquals(1536, $stats['dimension']);
        $this->assertEquals(15000, $stats['total_vector_count']);
        $this->assertEquals(0.3, $stats['index_fullness']);

        // Verify stats were recorded for monitoring
        $this->assertDatabaseHas('vector_index_stats', [
            'tenant_id' => $this->tenant->id,
            'vector_count' => 15000,
            'index_fullness' => 0.3,
        ]);
    }

    /** @test */
    public function it_supports_metadata_filtering()
    {
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => array_fill(0, 1536, 0.1)]],
            ], 200),
            'tenant-*.pinecone.io/query' => Http::response([
                'matches' => [
                    [
                        'id' => 'vec_123',
                        'score' => 0.95,
                        'metadata' => [
                            'meeting_id' => $this->meeting->id,
                            'speaker' => 'John Doe',
                            'date' => '2024-01-15',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $searchResults = $this->pineconeService->semanticSearch(
            $this->tenant,
            'project discussion',
            [
                'meeting_id' => $this->meeting->id,
                'speaker' => 'John Doe',
                'date' => ['$gte' => '2024-01-01', '$lt' => '2024-02-01'],
            ]
        );

        $this->assertTrue($searchResults['success']);
        $this->assertEquals('John Doe', $searchResults['matches'][0]['metadata']['speaker']);

        // Verify metadata filter was applied
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            return isset($body['filter']) &&
                   $body['filter']['meeting_id'] === $this->meeting->id &&
                   $body['filter']['speaker'] === 'John Doe';
        });
    }

    /** @test */
    public function it_handles_large_scale_similarity_search()
    {
        // Generate many similar results to test ranking
        $matches = [];
        for ($i = 0; $i < 100; $i++) {
            $matches[] = [
                'id' => "vec_{$i}",
                'score' => 1.0 - ($i * 0.01), // Decreasing similarity
                'metadata' => [
                    'text' => "Result {$i} with varying similarity scores",
                    'rank' => $i + 1,
                ],
            ];
        }

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => array_fill(0, 1536, 0.1)]],
            ], 200),
            'tenant-*.pinecone.io/query' => Http::response([
                'matches' => $matches,
            ], 200),
        ]);

        $searchResults = $this->pineconeService->semanticSearch(
            $this->tenant,
            'complex search query',
            [],
            100 // Request top 100 results
        );

        $this->assertTrue($searchResults['success']);
        $this->assertCount(100, $searchResults['matches']);

        // Verify results are properly sorted by similarity score
        $scores = array_column($searchResults['matches'], 'score');
        $this->assertEquals($scores, array_reverse(array_reverse($scores, true), true)); // Descending order
        $this->assertEquals(1.0, $scores[0]); // Highest score first
        $this->assertEquals(0.01, $scores[99]); // Lowest score last
    }

    /** @test */
    public function it_manages_vector_namespaces_for_organization()
    {
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => array_fill(0, 1536, 0.1)]],
            ], 200),
            'tenant-*.pinecone.io/vectors/upsert' => Http::response([
                'upsertedCount' => 1,
            ], 200),
        ]);

        // Store vectors in different namespaces
        $transcriptResult = $this->pineconeService->storeInNamespace(
            $this->tenant,
            'transcripts',
            ['transcript content'],
            ['type' => 'transcript']
        );

        $summaryResult = $this->pineconeService->storeInNamespace(
            $this->tenant,
            'summaries',
            ['meeting summary'],
            ['type' => 'summary']
        );

        $this->assertTrue($transcriptResult['success']);
        $this->assertTrue($summaryResult['success']);

        // Verify different namespaces were used
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'namespace=transcripts');
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'namespace=summaries');
        });
    }
}