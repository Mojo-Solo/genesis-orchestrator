<?php

namespace Tests\Feature\API;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Http\UploadedFile;
use App\Models\Meeting;
use App\Models\Transcript;
use App\Models\User;
use App\Models\Tenant;
use App\Jobs\ProcessMeetingJob;

/**
 * Feature Tests for Meeting Management API
 * 
 * Tests the complete API functionality including:
 * - CRUD operations for meetings
 * - Meeting execution workflow
 * - File upload and processing
 * - Real-time updates and WebSocket events
 * - Authentication and authorization
 * - Data validation and error handling
 * - Performance and pagination
 * - Multi-tenant isolation
 */
class MeetingAPITest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $adminUser;
    protected Tenant $tenant;
    protected string $apiBase = '/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'name' => 'Test Tenant',
            'tier' => 'professional',
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'user',
        ]);

        $this->adminUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'admin',
        ]);

        // Set up authentication
        $this->actingAs($this->user);
    }

    /** @test */
    public function it_can_list_meetings_with_pagination()
    {
        // Create test meetings
        Meeting::factory()->count(25)->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->getJson("{$this->apiBase}/meetings?page=1&per_page=10");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'description',
                        'status',
                        'scheduled_at',
                        'duration_minutes',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
                'links' => [
                    'first',
                    'last',
                    'prev',
                    'next',
                ],
            ]);

        $this->assertCount(10, $response->json('data'));
        $this->assertEquals(25, $response->json('meta.total'));
        $this->assertEquals(3, $response->json('meta.last_page'));
    }

    /** @test */
    public function it_can_filter_meetings_by_status()
    {
        Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'status' => 'scheduled',
        ]);

        Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'status' => 'completed',
        ]);

        $response = $this->getJson("{$this->apiBase}/meetings?status=scheduled");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('scheduled', $response->json('data.0.status'));
    }

    /** @test */
    public function it_can_search_meetings_by_title()
    {
        Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'title' => 'Quarterly Planning Meeting',
        ]);

        Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'title' => 'Daily Standup',
        ]);

        $response = $this->getJson("{$this->apiBase}/meetings?search=quarterly");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertStringContains('Quarterly', $response->json('data.0.title'));
    }

    /** @test */
    public function it_can_create_a_new_meeting()
    {
        $meetingData = [
            'title' => 'New API Test Meeting',
            'description' => 'Testing meeting creation via API',
            'scheduled_at' => now()->addDay()->toISOString(),
            'duration_minutes' => 60,
            'meeting_url' => 'https://zoom.us/j/123456789',
            'participants' => [
                ['email' => 'john@example.com', 'name' => 'John Doe'],
                ['email' => 'jane@example.com', 'name' => 'Jane Smith'],
            ],
        ];

        $response = $this->postJson("{$this->apiBase}/meetings", $meetingData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'title',
                    'description',
                    'status',
                    'scheduled_at',
                    'duration_minutes',
                    'meeting_url',
                    'participants',
                ],
                'message',
            ]);

        $this->assertEquals('New API Test Meeting', $response->json('data.title'));
        $this->assertEquals('scheduled', $response->json('data.status'));
        $this->assertCount(2, $response->json('data.participants'));

        // Verify in database
        $this->assertDatabaseHas('meetings', [
            'title' => 'New API Test Meeting',
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);
    }

    /** @test */
    public function it_validates_meeting_creation_data()
    {
        $invalidData = [
            'title' => '', // Required field
            'scheduled_at' => '2023-01-01', // Past date
            'duration_minutes' => -30, // Invalid duration
            'participants' => [
                ['email' => 'invalid-email'], // Invalid email format
            ],
        ];

        $response = $this->postJson("{$this->apiBase}/meetings", $invalidData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'title',
                'scheduled_at',
                'duration_minutes',
                'participants.0.email',
            ]);
    }

    /** @test */
    public function it_can_retrieve_a_specific_meeting()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'title' => 'Specific Meeting Test',
        ]);

        $response = $this->getJson("{$this->apiBase}/meetings/{$meeting->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'title',
                    'description',
                    'status',
                    'scheduled_at',
                    'duration_minutes',
                    'participants',
                    'recordings',
                    'transcripts',
                ],
            ]);

        $this->assertEquals($meeting->id, $response->json('data.id'));
        $this->assertEquals('Specific Meeting Test', $response->json('data.title'));
    }

    /** @test */
    public function it_enforces_tenant_isolation_for_meeting_access()
    {
        // Create meeting for different tenant
        $otherTenant = Tenant::factory()->create();
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherMeeting = Meeting::factory()->create([
            'tenant_id' => $otherTenant->id,
            'user_id' => $otherUser->id,
        ]);

        $response = $this->getJson("{$this->apiBase}/meetings/{$otherMeeting->id}");

        $response->assertStatus(404); // Should not be accessible
    }

    /** @test */
    public function it_can_update_a_meeting()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'title' => 'Original Title',
        ]);

        $updateData = [
            'title' => 'Updated Meeting Title',
            'description' => 'Updated description',
            'duration_minutes' => 90,
        ];

        $response = $this->putJson("{$this->apiBase}/meetings/{$meeting->id}", $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $meeting->id,
                    'title' => 'Updated Meeting Title',
                    'description' => 'Updated description',
                    'duration_minutes' => 90,
                ],
            ]);

        // Verify in database
        $this->assertDatabaseHas('meetings', [
            'id' => $meeting->id,
            'title' => 'Updated Meeting Title',
        ]);
    }

    /** @test */
    public function it_can_delete_a_meeting()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->deleteJson("{$this->apiBase}/meetings/{$meeting->id}");

        $response->assertStatus(204);

        // Verify soft deletion
        $this->assertSoftDeleted('meetings', ['id' => $meeting->id]);
    }

    /** @test */
    public function it_can_start_a_meeting()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'status' => 'scheduled',
        ]);

        $response = $this->postJson("{$this->apiBase}/meetings/{$meeting->id}/start");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'status' => 'in_progress',
                    'started_at' => now()->toISOString(),
                ],
                'message' => 'Meeting started successfully',
            ]);

        // Verify in database
        $this->assertDatabaseHas('meetings', [
            'id' => $meeting->id,
            'status' => 'in_progress',
        ]);
    }

    /** @test */
    public function it_can_end_a_meeting()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'status' => 'in_progress',
            'started_at' => now()->subHour(),
        ]);

        $response = $this->postJson("{$this->apiBase}/meetings/{$meeting->id}/end");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'status' => 'completed',
                    'ended_at' => now()->toISOString(),
                ],
                'message' => 'Meeting ended successfully',
            ]);

        // Verify actual duration was calculated
        $meeting->refresh();
        $this->assertNotNull($meeting->ended_at);
        $this->assertGreaterThan(0, $meeting->actual_duration_minutes);
    }

    /** @test */
    public function it_can_upload_meeting_recording()
    {
        Storage::fake('recordings');

        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'status' => 'completed',
        ]);

        $file = UploadedFile::fake()->create('meeting-recording.mp3', 5000, 'audio/mpeg');

        $response = $this->postJson("{$this->apiBase}/meetings/{$meeting->id}/recording", [
            'recording' => $file,
            'format' => 'mp3',
            'duration' => 3600,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'filename',
                    'file_path',
                    'format',
                    'duration',
                    'file_size',
                ],
                'message',
            ]);

        // Verify file was stored
        Storage::disk('recordings')->assertExists(
            $response->json('data.file_path')
        );

        // Verify in database
        $this->assertDatabaseHas('meeting_recordings', [
            'meeting_id' => $meeting->id,
            'format' => 'mp3',
            'duration' => 3600,
        ]);
    }

    /** @test */
    public function it_validates_recording_upload_format()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);

        $invalidFile = UploadedFile::fake()->create('document.pdf', 1000, 'application/pdf');

        $response = $this->postJson("{$this->apiBase}/meetings/{$meeting->id}/recording", [
            'recording' => $invalidFile,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['recording']);
    }

    /** @test */
    public function it_can_process_meeting_transcript()
    {
        Queue::fake();

        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'status' => 'completed',
        ]);

        $response = $this->postJson("{$this->apiBase}/meetings/{$meeting->id}/process");

        $response->assertStatus(202)
            ->assertJsonStructure([
                'data' => [
                    'job_id',
                    'status',
                    'estimated_completion',
                ],
                'message',
            ]);

        // Verify processing job was queued
        Queue::assertPushed(ProcessMeetingJob::class, function ($job) use ($meeting) {
            return $job->meeting->id === $meeting->id;
        });
    }

    /** @test */
    public function it_can_retrieve_meeting_transcript()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);

        $transcript = Transcript::factory()->create([
            'meeting_id' => $meeting->id,
            'content' => 'This is the meeting transcript content.',
            'sentences' => [
                [
                    'speaker' => 'John Doe',
                    'text' => 'Welcome to the meeting.',
                    'timestamp' => '00:01:00',
                    'confidence' => 0.98,
                ],
            ],
        ]);

        $response = $this->getJson("{$this->apiBase}/meetings/{$meeting->id}/transcript");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'content',
                    'language',
                    'confidence_score',
                    'sentences',
                    'created_at',
                ],
            ]);

        $this->assertEquals($transcript->id, $response->json('data.id'));
        $this->assertCount(1, $response->json('data.sentences'));
    }

    /** @test */
    public function it_can_generate_meeting_insights()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);

        Transcript::factory()->create([
            'meeting_id' => $meeting->id,
            'content' => 'Meeting about project updates and action items.',
        ]);

        $response = $this->postJson("{$this->apiBase}/meetings/{$meeting->id}/insights");

        $response->assertStatus(202)
            ->assertJsonStructure([
                'data' => [
                    'job_id',
                    'status',
                    'estimated_completion',
                ],
                'message',
            ]);
    }

    /** @test */
    public function it_can_retrieve_meeting_analytics()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'status' => 'completed',
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
        ]);

        $response = $this->getJson("{$this->apiBase}/meetings/{$meeting->id}/analytics");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'duration_analysis',
                    'participant_engagement',
                    'speaking_time_distribution',
                    'sentiment_analysis',
                    'key_topics',
                    'action_items_count',
                ],
            ]);
    }

    /** @test */
    public function it_handles_meeting_not_found()
    {
        $response = $this->getJson("{$this->apiBase}/meetings/999999");

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'Meeting not found',
                'error' => 'MEETING_NOT_FOUND',
            ]);
    }

    /** @test */
    public function it_enforces_role_based_permissions()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminUser->id, // Created by admin
        ]);

        // Regular user should be able to view
        $response = $this->getJson("{$this->apiBase}/meetings/{$meeting->id}");
        $response->assertStatus(200);

        // Regular user should not be able to delete
        $response = $this->deleteJson("{$this->apiBase}/meetings/{$meeting->id}");
        $response->assertStatus(403);

        // Admin should be able to delete
        $this->actingAs($this->adminUser);
        $response = $this->deleteJson("{$this->apiBase}/meetings/{$meeting->id}");
        $response->assertStatus(204);
    }

    /** @test */
    public function it_handles_rate_limiting()
    {
        // Make many requests in quick succession
        for ($i = 0; $i < 10; $i++) {
            $response = $this->getJson("{$this->apiBase}/meetings");
            
            if ($i < 5) {
                $response->assertStatus(200); // First few should succeed
            } else {
                // Later requests may be rate limited
                $this->assertContains($response->getStatusCode(), [200, 429]);
            }
        }
    }

    /** @test */
    public function it_handles_concurrent_meeting_updates()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'title' => 'Original Title',
        ]);

        // Simulate concurrent updates
        $responses = [];
        
        for ($i = 0; $i < 3; $i++) {
            $responses[] = $this->putJson("{$this->apiBase}/meetings/{$meeting->id}", [
                'title' => "Updated Title {$i}",
            ]);
        }

        // At least one update should succeed
        $successfulUpdates = array_filter($responses, fn($r) => $r->getStatusCode() === 200);
        $this->assertGreaterThanOrEqual(1, count($successfulUpdates));

        // Verify final state is consistent
        $meeting->refresh();
        $this->assertStringStartsWith('Updated Title', $meeting->title);
    }

    /** @test */
    public function it_supports_meeting_export_functionality()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);

        Transcript::factory()->create(['meeting_id' => $meeting->id]);

        $response = $this->getJson("{$this->apiBase}/meetings/{$meeting->id}/export?format=pdf");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/pdf');
    }

    /** @test */
    public function it_tracks_api_usage_metrics()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->getJson("{$this->apiBase}/meetings/{$meeting->id}");
        $response->assertStatus(200);

        // Verify API usage was tracked
        $this->assertDatabaseHas('api_usage_logs', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'endpoint' => "meetings/{$meeting->id}",
            'method' => 'GET',
            'status_code' => 200,
        ]);
    }

    /** @test */
    public function it_meets_performance_requirements()
    {
        // Create large dataset
        Meeting::factory()->count(100)->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);

        $startTime = microtime(true);
        $response = $this->getJson("{$this->apiBase}/meetings?per_page=50");
        $responseTime = microtime(true) - $startTime;

        $response->assertStatus(200);
        
        // Should respond within 200ms (Phase 7 requirement)
        $this->assertLessThan(0.2, $responseTime);
        
        // Should return proper pagination
        $this->assertCount(50, $response->json('data'));
    }
}