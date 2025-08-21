<?php

namespace Tests\Integration;

use Tests\TestCase;
use App\Services\FirefliesIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use App\Models\Meeting;
use App\Models\Transcript;
use App\Models\User;
use App\Models\Tenant;
use App\Jobs\ProcessTranscriptJob;

/**
 * Integration Tests for Fireflies API Integration
 * 
 * Tests the complete integration with Fireflies API including:
 * - Authentication and API connectivity
 * - Meeting upload and processing
 * - Transcript retrieval and parsing
 * - Webhook handling and real-time updates
 * - Error handling and retry mechanisms
 * - Multi-tenant isolation
 */
class FirefliesIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected FirefliesIntegrationService $firefliesService;
    protected User $user;
    protected Tenant $tenant;
    protected Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'name' => 'Test Tenant',
            'tier' => 'professional',
            'settings' => [
                'fireflies_api_key' => 'test_api_key_123',
                'fireflies_enabled' => true,
            ],
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'admin',
        ]);

        $this->meeting = Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'title' => 'Integration Test Meeting',
            'status' => 'completed',
        ]);

        $this->firefliesService = new FirefliesIntegrationService();
        $this->actingAs($this->user);
    }

    /** @test */
    public function it_can_authenticate_with_fireflies_api()
    {
        Http::fake([
            'api.fireflies.ai/graphql' => Http::response([
                'data' => [
                    'user' => [
                        'id' => 'fireflies_user_123',
                        'email' => $this->user->email,
                        'name' => $this->user->name,
                    ],
                ],
            ], 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        $result = $this->firefliesService->authenticateUser($this->tenant);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertEquals('fireflies_user_123', $result['user_id']);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test_api_key_123') &&
                   $request->url() === 'https://api.fireflies.ai/graphql' &&
                   str_contains($request->body(), 'query GetUser');
        });
    }

    /** @test */
    public function it_handles_authentication_failures()
    {
        Http::fake([
            'api.fireflies.ai/graphql' => Http::response([
                'errors' => [
                    ['message' => 'Invalid API key'],
                ],
            ], 401),
        ]);

        $result = $this->firefliesService->authenticateUser($this->tenant);

        $this->assertFalse($result['success']);
        $this->assertStringContains('Invalid API key', $result['error']);
    }

    /** @test */
    public function it_can_upload_meeting_recording_to_fireflies()
    {
        Storage::fake('recordings');
        Storage::disk('recordings')->put('test-recording.mp3', 'fake audio content');

        Http::fake([
            'api.fireflies.ai/graphql' => Http::response([
                'data' => [
                    'uploadAudio' => [
                        'id' => 'fireflies_meeting_123',
                        'title' => $this->meeting->title,
                        'status' => 'processing',
                        'upload_url' => 'https://upload.fireflies.ai/abc123',
                    ],
                ],
            ], 200),
            'upload.fireflies.ai/*' => Http::response('', 200),
        ]);

        $result = $this->firefliesService->uploadMeetingRecording(
            $this->meeting,
            'test-recording.mp3',
            ['format' => 'mp3', 'duration' => 3600]
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('fireflies_meeting_123', $result['fireflies_id']);
        $this->assertEquals('processing', $result['status']);

        // Verify meeting was updated with Fireflies ID
        $this->meeting->refresh();
        $this->assertEquals('fireflies_meeting_123', $this->meeting->fireflies_id);

        Http::assertSentCount(2); // GraphQL upload + file upload
    }

    /** @test */
    public function it_handles_upload_failures_with_retry()
    {
        Storage::fake('recordings');
        Storage::disk('recordings')->put('test-recording.mp3', 'fake audio content');

        // First attempt fails, second succeeds
        Http::fakeSequence()
            ->push('', 500) // First attempt fails
            ->push([
                'data' => [
                    'uploadAudio' => [
                        'id' => 'fireflies_meeting_123',
                        'title' => $this->meeting->title,
                        'status' => 'processing',
                    ],
                ],
            ], 200); // Second attempt succeeds

        $result = $this->firefliesService->uploadMeetingRecording(
            $this->meeting,
            'test-recording.mp3',
            ['format' => 'mp3', 'duration' => 3600]
        );

        $this->assertTrue($result['success']);
        Http::assertSentCount(2); // Retry mechanism worked
    }

    /** @test */
    public function it_can_retrieve_and_process_transcript()
    {
        $this->meeting->update(['fireflies_id' => 'fireflies_meeting_123']);

        $mockTranscriptData = [
            'data' => [
                'meeting' => [
                    'id' => 'fireflies_meeting_123',
                    'title' => $this->meeting->title,
                    'status' => 'completed',
                    'transcript' => [
                        'sentences' => [
                            [
                                'speaker_name' => 'John Doe',
                                'text' => 'Welcome everyone to today\'s meeting.',
                                'start_time' => 10.5,
                                'end_time' => 13.2,
                                'confidence' => 0.98,
                            ],
                            [
                                'speaker_name' => 'Jane Smith',
                                'text' => 'Thank you John. Let me share the project updates.',
                                'start_time' => 14.0,
                                'end_time' => 17.8,
                                'confidence' => 0.95,
                            ],
                        ],
                        'language' => 'en',
                        'confidence_score' => 0.96,
                    ],
                    'ai_insights' => [
                        'action_items' => [
                            [
                                'text' => 'John will prepare the quarterly report by Friday',
                                'assignee' => 'John Doe',
                                'due_date' => '2024-01-15',
                                'confidence' => 0.92,
                            ],
                        ],
                        'key_topics' => ['project updates', 'quarterly report'],
                        'sentiment' => 'positive',
                        'summary' => 'Team meeting discussing project progress and upcoming deliverables.',
                    ],
                ],
            ],
        ];

        Http::fake([
            'api.fireflies.ai/graphql' => Http::response($mockTranscriptData, 200),
        ]);

        $result = $this->firefliesService->retrieveTranscript($this->meeting);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('transcript', $result);
        $this->assertArrayHasKey('ai_insights', $result);

        // Verify transcript was saved to database
        $transcript = Transcript::where('meeting_id', $this->meeting->id)->first();
        $this->assertNotNull($transcript);
        $this->assertEquals('en', $transcript->language);
        $this->assertEquals(0.96, $transcript->confidence_score);
        $this->assertCount(2, $transcript->sentences);

        // Verify AI insights were extracted
        $this->assertArrayHasKey('action_items', $result['ai_insights']);
        $this->assertCount(1, $result['ai_insights']['action_items']);
    }

    /** @test */
    public function it_handles_webhook_notifications()
    {
        $this->meeting->update(['fireflies_id' => 'fireflies_meeting_123']);

        $webhookPayload = [
            'event' => 'transcript.completed',
            'meeting_id' => 'fireflies_meeting_123',
            'data' => [
                'status' => 'completed',
                'transcript_ready' => true,
                'processing_time' => 180,
            ],
            'timestamp' => now()->toISOString(),
        ];

        Queue::fake();

        $response = $this->postJson('/api/v1/webhooks/fireflies', $webhookPayload, [
            'X-Fireflies-Signature' => $this->generateWebhookSignature($webhookPayload),
        ]);

        $response->assertStatus(200);

        // Verify that transcript processing job was dispatched
        Queue::assertPushed(ProcessTranscriptJob::class, function ($job) {
            return $job->meeting->id === $this->meeting->id;
        });
    }

    /** @test */
    public function it_validates_webhook_signatures()
    {
        $webhookPayload = [
            'event' => 'transcript.completed',
            'meeting_id' => 'fireflies_meeting_123',
        ];

        // Test with invalid signature
        $response = $this->postJson('/api/v1/webhooks/fireflies', $webhookPayload, [
            'X-Fireflies-Signature' => 'invalid_signature',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Invalid webhook signature']);
    }

    /** @test */
    public function it_handles_multi_tenant_isolation()
    {
        // Create second tenant
        $tenant2 = Tenant::factory()->create([
            'name' => 'Second Tenant',
            'settings' => [
                'fireflies_api_key' => 'different_api_key_456',
                'fireflies_enabled' => true,
            ],
        ]);

        $user2 = User::factory()->create(['tenant_id' => $tenant2->id]);
        $meeting2 = Meeting::factory()->create([
            'tenant_id' => $tenant2->id,
            'user_id' => $user2->id,
        ]);

        Http::fake([
            'api.fireflies.ai/graphql' => Http::response([
                'data' => ['user' => ['id' => 'fireflies_user_456']],
            ], 200),
        ]);

        // Test first tenant
        $result1 = $this->firefliesService->authenticateUser($this->tenant);
        $this->assertTrue($result1['success']);

        // Test second tenant
        $result2 = $this->firefliesService->authenticateUser($tenant2);
        $this->assertTrue($result2['success']);

        // Verify correct API keys were used
        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test_api_key_123');
        });

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer different_api_key_456');
        });
    }

    /** @test */
    public function it_handles_rate_limiting_gracefully()
    {
        Http::fake([
            'api.fireflies.ai/graphql' => Http::response([
                'errors' => [['message' => 'Rate limit exceeded']],
            ], 429, [
                'Retry-After' => '60',
            ]),
        ]);

        $result = $this->firefliesService->authenticateUser($this->tenant);

        $this->assertFalse($result['success']);
        $this->assertStringContains('Rate limit exceeded', $result['error']);
        $this->assertEquals(60, $result['retry_after']);
    }

    /** @test */
    public function it_processes_large_transcripts_efficiently()
    {
        $this->meeting->update(['fireflies_id' => 'fireflies_meeting_123']);

        // Generate large transcript with 1000 sentences
        $sentences = [];
        for ($i = 0; $i < 1000; $i++) {
            $sentences[] = [
                'speaker_name' => "Speaker " . ($i % 5 + 1),
                'text' => "This is sentence number {$i} in the transcript.",
                'start_time' => $i * 2.5,
                'end_time' => ($i * 2.5) + 2.0,
                'confidence' => 0.90 + (mt_rand(0, 10) / 100),
            ];
        }

        $largeTranscriptData = [
            'data' => [
                'meeting' => [
                    'id' => 'fireflies_meeting_123',
                    'transcript' => [
                        'sentences' => $sentences,
                        'language' => 'en',
                        'confidence_score' => 0.94,
                    ],
                ],
            ],
        ];

        Http::fake([
            'api.fireflies.ai/graphql' => Http::response($largeTranscriptData, 200),
        ]);

        $startTime = microtime(true);
        $result = $this->firefliesService->retrieveTranscript($this->meeting);
        $processingTime = microtime(true) - $startTime;

        $this->assertTrue($result['success']);
        $this->assertLessThan(5.0, $processingTime); // Should process within 5 seconds

        // Verify all sentences were saved
        $transcript = Transcript::where('meeting_id', $this->meeting->id)->first();
        $this->assertCount(1000, $transcript->sentences);
    }

    /** @test */
    public function it_handles_partial_transcript_failures()
    {
        $this->meeting->update(['fireflies_id' => 'fireflies_meeting_123']);

        // Simulate partial transcript with some processing errors
        $partialTranscriptData = [
            'data' => [
                'meeting' => [
                    'id' => 'fireflies_meeting_123',
                    'status' => 'partial_failure',
                    'transcript' => [
                        'sentences' => [
                            [
                                'speaker_name' => 'John Doe',
                                'text' => 'This part was processed successfully.',
                                'start_time' => 10.5,
                                'end_time' => 13.2,
                                'confidence' => 0.98,
                            ],
                        ],
                        'language' => 'en',
                        'confidence_score' => 0.85,
                        'processing_errors' => [
                            'Audio quality poor between 15:30 and 18:45',
                            'Multiple speakers overlapping at 22:10',
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            'api.fireflies.ai/graphql' => Http::response($partialTranscriptData, 200),
        ]);

        $result = $this->firefliesService->retrieveTranscript($this->meeting);

        $this->assertTrue($result['success']);
        $this->assertEquals('partial_failure', $result['status']);
        $this->assertArrayHasKey('processing_errors', $result);
        $this->assertCount(2, $result['processing_errors']);

        // Verify partial transcript was still saved
        $transcript = Transcript::where('meeting_id', $this->meeting->id)->first();
        $this->assertNotNull($transcript);
        $this->assertEquals(0.85, $transcript->confidence_score);
    }

    /** @test */
    public function it_supports_multiple_languages()
    {
        $this->meeting->update(['fireflies_id' => 'fireflies_meeting_123']);

        $multilingualTranscript = [
            'data' => [
                'meeting' => [
                    'id' => 'fireflies_meeting_123',
                    'transcript' => [
                        'sentences' => [
                            [
                                'speaker_name' => 'Jean Dupont',
                                'text' => 'Bonjour tout le monde, commençons la réunion.',
                                'start_time' => 10.5,
                                'end_time' => 13.2,
                                'confidence' => 0.95,
                                'language' => 'fr',
                            ],
                            [
                                'speaker_name' => 'John Smith',
                                'text' => 'Thank you Jean, let me continue in English.',
                                'start_time' => 14.0,
                                'end_time' => 16.8,
                                'confidence' => 0.98,
                                'language' => 'en',
                            ],
                        ],
                        'primary_language' => 'en',
                        'detected_languages' => ['en', 'fr'],
                        'confidence_score' => 0.96,
                    ],
                ],
            ],
        ];

        Http::fake([
            'api.fireflies.ai/graphql' => Http::response($multilingualTranscript, 200),
        ]);

        $result = $this->firefliesService->retrieveTranscript($this->meeting);

        $this->assertTrue($result['success']);
        $this->assertEquals(['en', 'fr'], $result['transcript']['detected_languages']);

        $transcript = Transcript::where('meeting_id', $this->meeting->id)->first();
        $this->assertEquals('en', $transcript->language);
        $this->assertArrayHasKey('detected_languages', $transcript->metadata);
    }

    /**
     * Generate webhook signature for testing
     */
    private function generateWebhookSignature(array $payload): string
    {
        $secret = config('services.fireflies.webhook_secret', 'test_secret');
        return hash_hmac('sha256', json_encode($payload), $secret);
    }

    /** @test */
    public function it_handles_concurrent_webhook_processing()
    {
        // Create multiple meetings for concurrent testing
        $meetings = Meeting::factory()->count(5)->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);

        foreach ($meetings as $meeting) {
            $meeting->update(['fireflies_id' => 'fireflies_meeting_' . $meeting->id]);
        }

        Queue::fake();

        // Send multiple webhook notifications concurrently
        $promises = [];
        foreach ($meetings as $meeting) {
            $webhookPayload = [
                'event' => 'transcript.completed',
                'meeting_id' => 'fireflies_meeting_' . $meeting->id,
                'data' => ['status' => 'completed'],
                'timestamp' => now()->toISOString(),
            ];

            $promises[] = $this->postJson('/api/v1/webhooks/fireflies', $webhookPayload, [
                'X-Fireflies-Signature' => $this->generateWebhookSignature($webhookPayload),
            ]);
        }

        // All webhook requests should succeed
        foreach ($promises as $response) {
            $response->assertStatus(200);
        }

        // Verify all processing jobs were queued
        Queue::assertPushed(ProcessTranscriptJob::class, 5);
    }

    /** @test */
    public function it_tracks_api_usage_and_quotas()
    {
        Http::fake([
            'api.fireflies.ai/graphql' => Http::response([
                'data' => ['user' => ['id' => 'fireflies_user_123']],
            ], 200, [
                'X-RateLimit-Remaining' => '90',
                'X-RateLimit-Reset' => (time() + 3600),
            ]),
        ]);

        $result = $this->firefliesService->authenticateUser($this->tenant);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('rate_limit_remaining', $result);
        $this->assertEquals(90, $result['rate_limit_remaining']);

        // Verify usage tracking in database
        $this->assertDatabaseHas('api_usage_logs', [
            'tenant_id' => $this->tenant->id,
            'service' => 'fireflies',
            'endpoint' => 'graphql',
            'requests_count' => 1,
        ]);
    }
}