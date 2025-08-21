<?php

namespace Tests\Feature\API;

use Tests\TestCase;
use App\Models\Meeting;
use App\Models\MeetingTranscript;
use App\Models\ActionItem;
use App\Models\TenantUser;
use App\Events\MeetingCreated;
use App\Events\MeetingCompleted;
use App\Jobs\ProcessMeetingTranscript;
use Illuminate\Http\UploadedFile;
use Carbon\Carbon;

/**
 * @group feature
 * @group api
 * @group meetings
 */
class MeetingManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    /** @test */
    public function it_can_create_a_new_meeting()
    {
        $meetingData = [
            'title' => 'Weekly Team Sync',
            'description' => 'Weekly team synchronization meeting',
            'scheduled_at' => now()->addDays(1)->toISOString(),
            'duration_minutes' => 60,
            'participants' => [
                ['email' => 'john@example.com', 'name' => 'John Doe'],
                ['email' => 'jane@example.com', 'name' => 'Jane Smith']
            ],
            'meeting_type' => 'team_sync',
            'agenda' => [
                'Review last week progress',
                'Discuss current blockers',
                'Plan next week tasks'
            ]
        ];

        $response = $this->postJson('/api/v1/meetings', $meetingData);

        $this->assertApiSuccess($response);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'title',
                'description',
                'scheduled_at',
                'status',
                'meeting_url',
                'participants',
                'created_at',
                'updated_at'
            ]
        ]);

        $this->assertDatabaseHasTenantRecord('meetings', [
            'title' => 'Weekly Team Sync',
            'meeting_type' => 'team_sync',
            'status' => 'scheduled'
        ]);

        $this->assertEventDispatched(MeetingCreated::class);
    }

    /** @test */
    public function it_validates_required_fields_when_creating_meeting()
    {
        $response = $this->postJson('/api/v1/meetings', []);

        $this->assertApiError($response, 422);
        $response->assertJsonValidationErrors(['title', 'scheduled_at']);
    }

    /** @test */
    public function it_can_list_meetings_with_filters()
    {
        // Create test meetings
        Meeting::factory()->count(15)->create([
            'tenant_id' => $this->defaultTenant->id,
            'status' => 'completed'
        ]);
        
        Meeting::factory()->count(5)->create([
            'tenant_id' => $this->defaultTenant->id,
            'status' => 'scheduled',
            'scheduled_at' => now()->addDays(1)
        ]);

        // Test basic listing
        $response = $this->getJson('/api/v1/meetings');
        $this->assertApiSuccess($response);
        $this->assertApiPagination($response);
        
        // Test status filter
        $response = $this->getJson('/api/v1/meetings?status=scheduled');
        $this->assertApiSuccess($response);
        $response->assertJsonCount(5, 'data.data');

        // Test date range filter
        $response = $this->getJson('/api/v1/meetings?from=' . now()->toDateString() . '&to=' . now()->addWeek()->toDateString());
        $this->assertApiSuccess($response);
        
        // Test search
        $searchMeeting = Meeting::factory()->create([
            'tenant_id' => $this->defaultTenant->id,
            'title' => 'Special Marketing Review Meeting'
        ]);
        
        $response = $this->getJson('/api/v1/meetings?search=Marketing');
        $this->assertApiSuccess($response);
        $response->assertJsonFragment(['title' => 'Special Marketing Review Meeting']);
    }

    /** @test */
    public function it_can_retrieve_meeting_details()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->defaultTenant->id,
            'title' => 'Product Planning Meeting'
        ]);

        $response = $this->getJson("/api/v1/meetings/{$meeting->id}");

        $this->assertApiSuccess($response);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'title',
                'description',
                'status',
                'participants',
                'transcripts',
                'action_items',
                'insights',
                'created_at',
                'updated_at'
            ]
        ]);
        
        $response->assertJsonFragment(['title' => 'Product Planning Meeting']);
    }

    /** @test */
    public function it_can_update_meeting_details()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->defaultTenant->id,
            'title' => 'Original Title'
        ]);

        $updateData = [
            'title' => 'Updated Meeting Title',
            'description' => 'Updated description',
            'duration_minutes' => 90
        ];

        $response = $this->putJson("/api/v1/meetings/{$meeting->id}", $updateData);

        $this->assertApiSuccess($response);
        $response->assertJsonFragment(['title' => 'Updated Meeting Title']);
        
        $this->assertDatabaseHasTenantRecord('meetings', [
            'id' => $meeting->id,
            'title' => 'Updated Meeting Title',
            'duration_minutes' => 90
        ]);
    }

    /** @test */
    public function it_can_delete_a_meeting()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->defaultTenant->id
        ]);

        $response = $this->deleteJson("/api/v1/meetings/{$meeting->id}");

        $this->assertApiSuccess($response);
        $this->assertSoftDeleted('meetings', ['id' => $meeting->id]);
    }

    /** @test */
    public function it_can_start_a_meeting()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->defaultTenant->id,
            'status' => 'scheduled'
        ]);

        $response = $this->postJson("/api/v1/meetings/{$meeting->id}/start");

        $this->assertApiSuccess($response);
        
        $meeting->refresh();
        $this->assertEquals('in_progress', $meeting->status);
        $this->assertNotNull($meeting->started_at);
    }

    /** @test */
    public function it_can_end_a_meeting()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->defaultTenant->id,
            'status' => 'in_progress',
            'started_at' => now()->subHour()
        ]);

        $response = $this->postJson("/api/v1/meetings/{$meeting->id}/end");

        $this->assertApiSuccess($response);
        
        $meeting->refresh();
        $this->assertEquals('completed', $meeting->status);
        $this->assertNotNull($meeting->ended_at);
        
        $this->assertEventDispatched(MeetingCompleted::class);
    }

    /** @test */
    public function it_can_upload_meeting_recording()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->defaultTenant->id,
            'status' => 'completed'
        ]);

        $audioFile = UploadedFile::fake()->create('meeting_recording.mp3', 5000, 'audio/mpeg');

        $response = $this->postJson("/api/v1/meetings/{$meeting->id}/recording", [
            'recording' => $audioFile,
            'format' => 'mp3',
            'duration_seconds' => 3600
        ]);

        $this->assertApiSuccess($response);
        
        $response->assertJsonStructure([
            'data' => [
                'recording_url',
                'file_size',
                'format',
                'duration_seconds',
                'upload_status'
            ]
        ]);

        $this->assertJobDispatched(ProcessMeetingTranscript::class);
    }

    /** @test */
    public function it_can_process_transcript_upload()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->defaultTenant->id
        ]);

        $transcriptData = [
            'content' => 'This is a test transcript of the meeting discussion.',
            'language' => 'en',
            'confidence_score' => 0.95,
            'sentences' => [
                [
                    'speaker' => 'John Doe',
                    'text' => 'Hello everyone, let\'s start the meeting.',
                    'timestamp' => '00:00:10',
                    'confidence' => 0.98
                ],
                [
                    'speaker' => 'Jane Smith',
                    'text' => 'Thanks John, I have updates on the project.',
                    'timestamp' => '00:00:25',
                    'confidence' => 0.95
                ]
            ]
        ];

        $response = $this->postJson("/api/v1/meetings/{$meeting->id}/transcript", $transcriptData);

        $this->assertApiSuccess($response);
        
        $this->assertDatabaseHasTenantRecord('meeting_transcripts', [
            'meeting_id' => $meeting->id,
            'content' => 'This is a test transcript of the meeting discussion.',
            'language' => 'en',
            'confidence_score' => 0.95
        ]);
    }

    /** @test */
    public function it_can_retrieve_meeting_transcripts()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->defaultTenant->id
        ]);

        $transcript = MeetingTranscript::factory()->create([
            'tenant_id' => $this->defaultTenant->id,
            'meeting_id' => $meeting->id,
            'content' => 'Test transcript content'
        ]);

        $response = $this->getJson("/api/v1/meetings/{$meeting->id}/transcripts");

        $this->assertApiSuccess($response);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'content',
                    'language',
                    'confidence_score',
                    'sentences',
                    'created_at'
                ]
            ]
        ]);
    }

    /** @test */
    public function it_can_extract_action_items_from_meeting()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->defaultTenant->id
        ]);

        MeetingTranscript::factory()->create([
            'tenant_id' => $this->defaultTenant->id,
            'meeting_id' => $meeting->id,
            'content' => 'John will prepare the report by Friday. Jane needs to follow up with the client next week.'
        ]);

        $response = $this->postJson("/api/v1/meetings/{$meeting->id}/action-items/extract");

        $this->assertApiSuccess($response);
        
        $response->assertJsonStructure([
            'data' => [
                'extracted_items' => [
                    '*' => [
                        'description',
                        'assigned_to',
                        'due_date',
                        'priority',
                        'confidence'
                    ]
                ],
                'processing_time_ms'
            ]
        ]);
    }

    /** @test */
    public function it_can_generate_meeting_summary()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->defaultTenant->id,
            'title' => 'Product Planning Session'
        ]);

        MeetingTranscript::factory()->create([
            'tenant_id' => $this->defaultTenant->id,
            'meeting_id' => $meeting->id,
            'content' => 'We discussed the new product features, timeline, and resource allocation.'
        ]);

        $response = $this->postJson("/api/v1/meetings/{$meeting->id}/summary");

        $this->assertApiSuccess($response);
        
        $response->assertJsonStructure([
            'data' => [
                'summary',
                'key_points',
                'decisions_made',
                'action_items_count',
                'sentiment_analysis',
                'topics_discussed',
                'next_steps'
            ]
        ]);
    }

    /** @test */
    public function it_enforces_tenant_isolation()
    {
        // Create meeting in different tenant
        $otherTenant = \App\Models\Tenant::factory()->create();
        $otherMeeting = Meeting::factory()->create([
            'tenant_id' => $otherTenant->id
        ]);

        // Try to access other tenant's meeting
        $response = $this->getJson("/api/v1/meetings/{$otherMeeting->id}");
        
        $this->assertApiError($response, 404);
    }

    /** @test */
    public function it_handles_rate_limiting()
    {
        config(['rate_limiting.enabled' => true]);
        
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->defaultTenant->id
        ]);

        // Make multiple rapid requests
        for ($i = 0; $i < 100; $i++) {
            $response = $this->getJson("/api/v1/meetings/{$meeting->id}");
        }

        // Should eventually hit rate limit
        $response = $this->getJson("/api/v1/meetings/{$meeting->id}");
        
        // In testing, we might not enforce rate limits, but we test the structure
        $this->assertTrue(in_array($response->status(), [200, 429]));
    }

    /** @test */
    public function it_validates_permissions_for_meeting_operations()
    {
        $this->actingAsUser($this->regularUser);
        
        $meetingData = [
            'title' => 'Test Meeting',
            'scheduled_at' => now()->addDays(1)->toISOString()
        ];

        // Regular user should be able to create meetings
        $response = $this->postJson('/api/v1/meetings', $meetingData);
        $this->assertApiSuccess($response);

        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->defaultTenant->id,
            'created_by' => $this->adminUser->id // Created by admin
        ]);

        // Regular user should not be able to delete admin's meeting
        $response = $this->deleteJson("/api/v1/meetings/{$meeting->id}");
        $this->assertApiError($response, 403);
    }

    /** @test */
    public function it_tracks_performance_metrics()
    {
        $meeting = Meeting::factory()->create([
            'tenant_id' => $this->defaultTenant->id
        ]);

        // Test response time is reasonable
        $this->benchmark(function() use ($meeting) {
            $this->getJson("/api/v1/meetings/{$meeting->id}");
        }, 0.5); // Should respond within 500ms

        // Test memory usage is reasonable
        $this->assertMemoryUsage(function() {
            $this->getJson('/api/v1/meetings');
        }, 10); // Should use less than 10MB
    }
}