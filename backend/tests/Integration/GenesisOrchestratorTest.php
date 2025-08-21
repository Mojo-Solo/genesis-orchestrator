<?php

namespace Tests\Integration;

use Tests\TestCase;
use App\Services\GenesisOrchestratorService;
use App\Services\AdvancedSecurityService;
use App\Services\FirefliesIntegrationService;
use App\Services\PineconeVectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use App\Models\Meeting;
use App\Models\Transcript;
use App\Models\User;
use App\Models\Tenant;
use App\Models\ProcessingJob;
use App\Events\MeetingProcessingCompleted;
use App\Jobs\ProcessMeetingJob;
use App\Jobs\GenerateInsightsJob;

/**
 * Integration Tests for GENESIS Orchestrator
 * 
 * Tests the complete orchestration system including:
 * - LAG decomposition and task orchestration
 * - RCR routing and load balancing
 * - Multi-tenant processing isolation
 * - Real-time processing pipeline
 * - Error handling and recovery
 * - Performance optimization
 * - Security enforcement
 * - End-to-end meeting processing workflow
 */
class GenesisOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    protected GenesisOrchestratorService $orchestrator;
    protected AdvancedSecurityService $securityService;
    protected User $user;
    protected Tenant $tenant;
    protected Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'name' => 'Test Tenant',
            'tier' => 'enterprise',
            'settings' => [
                'processing_enabled' => true,
                'max_concurrent_jobs' => 10,
                'priority_level' => 'high',
            ],
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'admin',
        ]);

        $this->meeting = Meeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'title' => 'GENESIS Test Meeting',
            'status' => 'completed',
            'metadata' => [
                'recording_duration' => 3600,
                'participant_count' => 5,
                'has_recording' => true,
            ],
        ]);

        $this->orchestrator = new GenesisOrchestratorService();
        $this->securityService = new AdvancedSecurityService();
        $this->actingAs($this->user);
    }

    /** @test */
    public function it_orchestrates_complete_meeting_processing_pipeline()
    {
        Queue::fake();
        Event::fake();

        // Mock external service responses
        Http::fake([
            'api.fireflies.ai/graphql' => Http::response([
                'data' => [
                    'meeting' => [
                        'id' => 'fireflies_123',
                        'transcript' => [
                            'sentences' => [
                                [
                                    'speaker_name' => 'John Doe',
                                    'text' => 'Let\'s discuss the quarterly targets and KPIs.',
                                    'start_time' => 10.5,
                                    'confidence' => 0.98,
                                ],
                            ],
                            'confidence_score' => 0.95,
                        ],
                        'ai_insights' => [
                            'action_items' => [
                                [
                                    'text' => 'Prepare Q4 performance report',
                                    'assignee' => 'John Doe',
                                    'confidence' => 0.92,
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => array_fill(0, 1536, 0.1)]],
            ], 200),
            'tenant-*.pinecone.io/vectors/upsert' => Http::response([
                'upsertedCount' => 1,
            ], 200),
        ]);

        // Start orchestration
        $result = $this->orchestrator->orchestrateMeetingProcessing($this->meeting);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('orchestration_id', $result);
        $this->assertArrayHasKey('estimated_completion', $result);

        // Verify LAG decomposition created proper job sequence
        Queue::assertPushed(ProcessMeetingJob::class, function ($job) {
            return $job->meeting->id === $this->meeting->id &&
                   $job->phase === 'transcript_processing';
        });

        Queue::assertPushed(GenerateInsightsJob::class, function ($job) {
            return $job->meeting->id === $this->meeting->id &&
                   $job->dependencies === ['transcript_processing'];
        });

        // Verify processing job was recorded
        $this->assertDatabaseHas('processing_jobs', [
            'tenant_id' => $this->tenant->id,
            'meeting_id' => $this->meeting->id,
            'type' => 'orchestration',
            'status' => 'running',
        ]);
    }

    /** @test */
    public function it_implements_rcr_routing_for_load_balancing()
    {
        // Create multiple meetings for load testing
        $meetings = Meeting::factory()->count(15)->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);

        Queue::fake();

        // Process all meetings concurrently
        $orchestrationResults = [];
        foreach ($meetings as $meeting) {
            $result = $this->orchestrator->orchestrateMeetingProcessing($meeting);
            $orchestrationResults[] = $result;
        }

        // Verify RCR routing distributed load
        $this->assertCount(15, $orchestrationResults);
        
        // All should be successful
        foreach ($orchestrationResults as $result) {
            $this->assertTrue($result['success']);
            $this->assertArrayHasKey('assigned_worker', $result);
            $this->assertArrayHasKey('queue_position', $result);
        }

        // Verify load was distributed across available workers
        $assignedWorkers = array_column($orchestrationResults, 'assigned_worker');
        $uniqueWorkers = array_unique($assignedWorkers);
        $this->assertGreaterThan(1, count($uniqueWorkers)); // Load should be distributed

        // Verify queue management
        Queue::assertPushed(ProcessMeetingJob::class, 15);
    }

    /** @test */
    public function it_enforces_multi_tenant_isolation_in_processing()
    {
        // Create second tenant
        $tenant2 = Tenant::factory()->create([
            'name' => 'Second Tenant',
            'tier' => 'professional',
        ]);

        $meeting2 = Meeting::factory()->create([
            'tenant_id' => $tenant2->id,
        ]);

        Queue::fake();

        // Process meetings from both tenants
        $result1 = $this->orchestrator->orchestrateMeetingProcessing($this->meeting);
        $result2 = $this->orchestrator->orchestrateMeetingProcessing($meeting2);

        $this->assertTrue($result1['success']);
        $this->assertTrue($result2['success']);

        // Verify tenant isolation in job metadata
        Queue::assertPushed(ProcessMeetingJob::class, function ($job) {
            return $job->meeting->tenant_id === $this->tenant->id &&
                   $job->tenant_context['tier'] === 'enterprise';
        });

        Queue::assertPushed(ProcessMeetingJob::class, function ($job) use ($tenant2) {
            return $job->meeting->tenant_id === $tenant2->id &&
                   $job->tenant_context['tier'] === 'professional';
        });

        // Verify processing jobs are isolated
        $this->assertDatabaseHas('processing_jobs', [
            'tenant_id' => $this->tenant->id,
            'isolation_level' => 'strict',
        ]);

        $this->assertDatabaseHas('processing_jobs', [
            'tenant_id' => $tenant2->id,
            'isolation_level' => 'strict',
        ]);
    }

    /** @test */
    public function it_handles_processing_errors_with_recovery()
    {
        Queue::fake();

        // Mock API failure
        Http::fake([
            'api.fireflies.ai/graphql' => Http::response([
                'errors' => [['message' => 'Service temporarily unavailable']],
            ], 503),
        ]);

        $result = $this->orchestrator->orchestrateMeetingProcessing($this->meeting);

        $this->assertTrue($result['success']); // Orchestration starts successfully
        
        // Simulate job failure and retry mechanism
        $job = new ProcessMeetingJob($this->meeting, 'transcript_processing');
        
        try {
            $job->handle();
        } catch (\Exception $e) {
            // Should trigger retry mechanism
            $this->assertStringContains('Service temporarily unavailable', $e->getMessage());
        }

        // Verify error was logged and retry was scheduled
        $this->assertDatabaseHas('processing_jobs', [
            'meeting_id' => $this->meeting->id,
            'status' => 'failed',
            'retry_count' => 1,
        ]);

        // Verify recovery job was queued
        Queue::assertPushed(ProcessMeetingJob::class, function ($job) {
            return $job->meeting->id === $this->meeting->id &&
                   $job->isRetry === true;
        });
    }

    /** @test */
    public function it_optimizes_performance_with_caching()
    {
        Cache::shouldReceive('remember')
            ->once()
            ->with(
                "orchestration_plan_{$this->meeting->id}",
                3600,
                \Mockery::type('callable')
            )
            ->andReturn([
                'phases' => ['transcript_processing', 'insight_generation'],
                'dependencies' => [
                    'insight_generation' => ['transcript_processing'],
                ],
                'estimated_time' => 300,
            ]);

        Queue::fake();

        // First orchestration should cache the plan
        $result1 = $this->orchestrator->orchestrateMeetingProcessing($this->meeting);
        
        // Second orchestration should use cached plan
        $result2 = $this->orchestrator->orchestrateMeetingProcessing($this->meeting);

        $this->assertTrue($result1['success']);
        $this->assertTrue($result2['success']);
        $this->assertEquals($result1['estimated_completion'], $result2['estimated_completion']);
    }

    /** @test */
    public function it_applies_security_enforcement_during_processing()
    {
        Queue::fake();

        // Mock security service
        $this->mock(AdvancedSecurityService::class, function ($mock) {
            $mock->shouldReceive('validateProcessingRequest')
                ->once()
                ->with($this->meeting, $this->user)
                ->andReturn(['valid' => true, 'risk_score' => 0.1]);

            $mock->shouldReceive('encryptSensitiveData')
                ->once()
                ->andReturn('encrypted_data');

            $mock->shouldReceive('auditProcessingAccess')
                ->once()
                ->with($this->meeting->id, $this->user->id, 'orchestration_start');
        });

        $result = $this->orchestrator->orchestrateMeetingProcessing($this->meeting);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('security_validation', $result);
        $this->assertTrue($result['security_validation']['passed']);
    }

    /** @test */
    public function it_tracks_processing_metrics_and_analytics()
    {
        Queue::fake();

        $startTime = microtime(true);
        $result = $this->orchestrator->orchestrateMeetingProcessing($this->meeting);
        $processingTime = microtime(true) - $startTime;

        $this->assertTrue($result['success']);

        // Verify metrics were recorded
        $this->assertDatabaseHas('orchestration_metrics', [
            'tenant_id' => $this->tenant->id,
            'meeting_id' => $this->meeting->id,
            'orchestration_time' => $processingTime,
            'queue_depth' => 1,
            'estimated_completion' => $result['estimated_completion'],
        ]);

        // Verify analytics data
        $this->assertDatabaseHas('processing_analytics', [
            'tenant_id' => $this->tenant->id,
            'date' => now()->toDateString(),
            'meetings_processed' => 1,
            'avg_processing_time' => $processingTime,
        ]);
    }

    /** @test */
    public function it_handles_priority_processing_for_enterprise_tiers()
    {
        // Create standard tier tenant
        $standardTenant = Tenant::factory()->create([
            'tier' => 'standard',
        ]);

        $standardMeeting = Meeting::factory()->create([
            'tenant_id' => $standardTenant->id,
        ]);

        Queue::fake();

        // Process both meetings
        $enterpriseResult = $this->orchestrator->orchestrateMeetingProcessing($this->meeting);
        $standardResult = $this->orchestrator->orchestrateMeetingProcessing($standardMeeting);

        $this->assertTrue($enterpriseResult['success']);
        $this->assertTrue($standardResult['success']);

        // Verify enterprise meeting gets higher priority
        $this->assertLessThan(
            $standardResult['queue_position'],
            $enterpriseResult['queue_position']
        );

        // Verify different job priorities
        Queue::assertPushed(ProcessMeetingJob::class, function ($job) {
            return $job->meeting->id === $this->meeting->id &&
                   $job->priority === 'high';
        });

        Queue::assertPushed(ProcessMeetingJob::class, function ($job) use ($standardMeeting) {
            return $job->meeting->id === $standardMeeting->id &&
                   $job->priority === 'normal';
        });
    }

    /** @test */
    public function it_implements_real_time_progress_updates()
    {
        Queue::fake();
        Event::fake();

        $result = $this->orchestrator->orchestrateMeetingProcessing($this->meeting);
        $orchestrationId = $result['orchestration_id'];

        // Simulate processing phases
        $this->orchestrator->updateProcessingProgress($orchestrationId, 'transcript_processing', 50);
        $this->orchestrator->updateProcessingProgress($orchestrationId, 'transcript_processing', 100);
        $this->orchestrator->updateProcessingProgress($orchestrationId, 'insight_generation', 30);

        // Verify progress updates were broadcast
        Event::assertDispatched('processing.progress.updated', function ($event) use ($orchestrationId) {
            return $event['orchestration_id'] === $orchestrationId &&
                   $event['phase'] === 'transcript_processing' &&
                   $event['progress'] === 50;
        });

        // Verify progress was stored
        $this->assertDatabaseHas('processing_progress', [
            'orchestration_id' => $orchestrationId,
            'phase' => 'transcript_processing',
            'progress' => 100,
            'status' => 'completed',
        ]);
    }

    /** @test */
    public function it_supports_batch_processing_optimization()
    {
        // Create multiple meetings for batch processing
        $meetings = Meeting::factory()->count(25)->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'status' => 'completed',
        ]);

        Queue::fake();

        $startTime = microtime(true);
        $result = $this->orchestrator->batchProcessMeetings($meetings->pluck('id')->toArray());
        $batchTime = microtime(true) - $startTime;

        $this->assertTrue($result['success']);
        $this->assertEquals(25, $result['meetings_queued']);
        $this->assertLessThan(5.0, $batchTime); // Should complete batch setup quickly

        // Verify batch optimization was applied
        $this->assertArrayHasKey('batch_id', $result);
        $this->assertArrayHasKey('optimized_schedule', $result);

        // Verify jobs were batched efficiently
        Queue::assertPushed(ProcessMeetingJob::class, 25);
        
        // Verify batch metadata
        $this->assertDatabaseHas('batch_processing_jobs', [
            'tenant_id' => $this->tenant->id,
            'batch_id' => $result['batch_id'],
            'total_meetings' => 25,
            'status' => 'queued',
        ]);
    }

    /** @test */
    public function it_enforces_resource_limits_and_quotas()
    {
        // Update tenant to have processing limits
        $this->tenant->update([
            'settings' => [
                'processing_enabled' => true,
                'max_concurrent_jobs' => 2,
                'monthly_processing_limit' => 10,
            ],
        ]);

        Queue::fake();

        // Create more meetings than the limit
        $meetings = Meeting::factory()->count(15)->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);

        $results = [];
        foreach ($meetings as $meeting) {
            $result = $this->orchestrator->orchestrateMeetingProcessing($meeting);
            $results[] = $result;
        }

        // First 10 should succeed (within monthly limit)
        $successfulResults = array_filter($results, fn($r) => $r['success']);
        $this->assertCount(10, $successfulResults);

        // Remaining should be queued for next period
        $queuedResults = array_filter($results, fn($r) => !$r['success'] && $r['reason'] === 'quota_exceeded');
        $this->assertCount(5, $queuedResults);

        // Verify quota tracking
        $this->assertDatabaseHas('tenant_quotas', [
            'tenant_id' => $this->tenant->id,
            'period' => now()->format('Y-m'),
            'meetings_processed' => 10,
            'quota_exceeded' => true,
        ]);
    }

    /** @test */
    public function it_generates_comprehensive_processing_reports()
    {
        Queue::fake();

        // Process multiple meetings
        $meetings = Meeting::factory()->count(5)->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);

        foreach ($meetings as $meeting) {
            $this->orchestrator->orchestrateMeetingProcessing($meeting);
        }

        // Generate processing report
        $report = $this->orchestrator->generateProcessingReport($this->tenant, [
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'include_metrics' => true,
            'include_errors' => true,
        ]);

        $this->assertTrue($report['success']);
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('metrics', $report);
        $this->assertArrayHasKey('error_analysis', $report);

        // Verify report content
        $this->assertEquals(5, $report['summary']['total_meetings']);
        $this->assertArrayHasKey('avg_processing_time', $report['metrics']);
        $this->assertArrayHasKey('success_rate', $report['metrics']);
        $this->assertArrayHasKey('queue_efficiency', $report['metrics']);
    }

    /** @test */
    public function it_handles_system_health_monitoring()
    {
        $healthCheck = $this->orchestrator->performHealthCheck();

        $this->assertTrue($healthCheck['healthy']);
        $this->assertArrayHasKey('components', $healthCheck);
        $this->assertArrayHasKey('metrics', $healthCheck);

        // Verify component health checks
        $components = $healthCheck['components'];
        $this->assertTrue($components['queue']['healthy']);
        $this->assertTrue($components['database']['healthy']);
        $this->assertTrue($components['cache']['healthy']);
        $this->assertTrue($components['storage']['healthy']);

        // Verify metrics are present
        $metrics = $healthCheck['metrics'];
        $this->assertArrayHasKey('active_jobs', $metrics);
        $this->assertArrayHasKey('queue_depth', $metrics);
        $this->assertArrayHasKey('processing_rate', $metrics);
        $this->assertArrayHasKey('error_rate', $metrics);
    }

    /** @test */
    public function it_supports_processing_pipeline_customization()
    {
        Queue::fake();

        // Define custom processing pipeline
        $customPipeline = [
            'phases' => [
                'audio_preprocessing',
                'transcript_processing',
                'custom_analysis',
                'insight_generation',
                'report_generation',
            ],
            'dependencies' => [
                'transcript_processing' => ['audio_preprocessing'],
                'custom_analysis' => ['transcript_processing'],
                'insight_generation' => ['custom_analysis'],
                'report_generation' => ['insight_generation'],
            ],
            'custom_processors' => [
                'custom_analysis' => 'App\\Processors\\CustomAnalysisProcessor',
            ],
        ];

        $result = $this->orchestrator->orchestrateMeetingProcessing(
            $this->meeting,
            $customPipeline
        );

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('custom_pipeline', $result);
        $this->assertEquals(5, count($result['custom_pipeline']['phases']));

        // Verify custom pipeline was used
        Queue::assertPushed(ProcessMeetingJob::class, function ($job) {
            return $job->phase === 'custom_analysis' &&
                   $job->processor === 'App\\Processors\\CustomAnalysisProcessor';
        });
    }
}