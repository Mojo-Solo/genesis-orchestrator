<?php

namespace App\GraphQL\Resolvers;

use App\Models\Meeting;
use App\Models\User;
use App\Models\Tenant;
use App\Services\AdvancedSecurityService;
use App\Services\AdvancedMonitoringService;
use App\Services\FirefliesIntegrationService;
use App\Services\PineconeVectorService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Carbon\Carbon;

/**
 * Meeting GraphQL Resolver
 * 
 * Handles all meeting-related GraphQL operations with comprehensive
 * real-time features, analytics, security validation, and AI integration
 */
class MeetingResolver
{
    protected AdvancedSecurityService $securityService;
    protected AdvancedMonitoringService $monitoringService;
    protected FirefliesIntegrationService $firefliesService;
    protected PineconeVectorService $vectorService;

    public function __construct(
        AdvancedSecurityService $securityService,
        AdvancedMonitoringService $monitoringService,
        FirefliesIntegrationService $firefliesService,
        PineconeVectorService $vectorService
    ) {
        $this->securityService = $securityService;
        $this->monitoringService = $monitoringService;
        $this->firefliesService = $firefliesService;
        $this->vectorService = $vectorService;
    }

    /**
     * Resolve single meeting by ID with authorization checks
     */
    public function meeting($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): ?Meeting
    {
        $startTime = microtime(true);
        
        try {
            $meetingId = $args['id'];
            $currentUser = Auth::user();

            // Security validation
            $this->securityService->validateRequest($context->request());
            
            // Load meeting with relationships
            $meeting = Meeting::with([
                'user',
                'tenant',
                'participants',
                'recordings',
                'transcripts',
                'actionItems',
                'insights'
            ])->find($meetingId);

            if (!$meeting) {
                $this->monitoringService->recordMetric('graphql.meeting.not_found', 1, [
                    'meeting_id' => $meetingId,
                    'user_id' => $currentUser?->id,
                ]);
                return null;
            }

            // Authorization check
            if (!$this->securityService->canViewMeeting($currentUser, $meeting)) {
                $this->monitoringService->recordSecurityEvent('unauthorized_meeting_access', [
                    'requesting_user_id' => $currentUser?->id,
                    'meeting_id' => $meetingId,
                    'tenant_id' => $meeting->tenant_id,
                    'ip_address' => $context->request()->ip(),
                ]);
                throw new \Exception('Unauthorized access to meeting data');
            }

            // Record successful access
            $this->monitoringService->recordMetric('graphql.meeting.success', 1, [
                'meeting_id' => $meetingId,
                'user_id' => $currentUser->id,
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);

            return $meeting;

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('graphql.meeting.error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Resolve paginated meetings with advanced filtering
     */
    public function meetings($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo)
    {
        $startTime = microtime(true);
        
        try {
            $currentUser = Auth::user();
            
            // Security validation
            $this->securityService->validateRequest($context->request());
            
            $first = $args['first'] ?? 10;
            $first = min($first, 100); // Enforce maximum limit
            $filters = $args['filters'] ?? [];

            // Build query with tenant isolation
            $query = Meeting::query()
                ->where('tenant_id', $currentUser->tenant_id)
                ->with(['user', 'participants'])
                ->orderBy('scheduled_at', 'desc');

            // Apply authorization filters
            if (!$this->securityService->canViewAllMeetings($currentUser)) {
                // Only show meetings user created or participated in
                $query->where(function ($q) use ($currentUser) {
                    $q->where('user_id', $currentUser->id)
                      ->orWhereJsonContains('participants', ['email' => $currentUser->email]);
                });
            }

            // Apply filters
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (isset($filters['date_from'])) {
                $query->where('scheduled_at', '>=', $filters['date_from']);
            }

            if (isset($filters['date_to'])) {
                $query->where('scheduled_at', '<=', $filters['date_to']);
            }

            if (isset($filters['search'])) {
                $search = $filters['search'];
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'ilike', "%{$search}%")
                      ->orWhere('description', 'ilike', "%{$search}%");
                });
            }

            if (isset($filters['user_id'])) {
                $query->where('user_id', $filters['user_id']);
            }

            $meetings = $query->paginate($first);

            // Record successful query
            $this->monitoringService->recordMetric('graphql.meetings.success', 1, [
                'count' => $meetings->count(),
                'total' => $meetings->total(),
                'user_id' => $currentUser->id,
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);

            return $meetings;

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('graphql.meetings.error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Create new meeting with comprehensive validation
     */
    public function createMeeting($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Meeting
    {
        $startTime = microtime(true);
        
        try {
            $currentUser = Auth::user();
            $input = $args['input'];

            // Security validation
            $this->securityService->validateRequest($context->request());
            
            // Authorization check
            if (!$this->securityService->canCreateMeeting($currentUser)) {
                throw new \Exception('Insufficient permissions to create meetings');
            }

            // Validate meeting data
            $this->validateMeetingInput($input, $currentUser);

            DB::beginTransaction();

            try {
                // Create meeting
                $meeting = new Meeting([
                    'title' => $input['title'],
                    'description' => $input['description'] ?? null,
                    'scheduled_at' => $input['scheduled_at'],
                    'duration_minutes' => $input['duration_minutes'],
                    'meeting_url' => $input['meeting_url'] ?? null,
                    'status' => 'scheduled',
                    'user_id' => $currentUser->id,
                    'tenant_id' => $currentUser->tenant_id,
                    'participants' => $input['participants'] ?? [],
                ]);

                $meeting->save();

                // Create Fireflies integration if meeting URL provided
                if ($meeting->meeting_url) {
                    $this->firefliesService->scheduleBot([
                        'meeting_url' => $meeting->meeting_url,
                        'title' => $meeting->title,
                        'scheduled_at' => $meeting->scheduled_at,
                        'metadata' => [
                            'meeting_id' => $meeting->id,
                            'tenant_id' => $meeting->tenant_id,
                            'user_id' => $meeting->user_id,
                        ],
                    ]);
                }

                DB::commit();

                // Load relationships for response
                $meeting->load(['user', 'tenant', 'participants']);

                // Record successful creation
                $this->monitoringService->recordMetric('graphql.meeting.created', 1, [
                    'meeting_id' => $meeting->id,
                    'user_id' => $currentUser->id,
                    'tenant_id' => $meeting->tenant_id,
                    'execution_time_ms' => (microtime(true) - $startTime) * 1000,
                ]);

                // Clear relevant caches
                $this->clearMeetingCaches($currentUser->tenant_id);

                return $meeting;

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('graphql.meeting.create.error', 1, [
                'error' => $e->getMessage(),
                'user_id' => $currentUser?->id,
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Update existing meeting
     */
    public function updateMeeting($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Meeting
    {
        $startTime = microtime(true);
        
        try {
            $meetingId = $args['id'];
            $input = $args['input'];
            $currentUser = Auth::user();

            // Security validation
            $this->securityService->validateRequest($context->request());
            
            $meeting = Meeting::findOrFail($meetingId);

            // Authorization check
            if (!$this->securityService->canUpdateMeeting($currentUser, $meeting)) {
                throw new \Exception('Insufficient permissions to update this meeting');
            }

            DB::beginTransaction();

            try {
                // Update meeting fields
                foreach ($input as $field => $value) {
                    if (in_array($field, ['title', 'description', 'scheduled_at', 'duration_minutes', 'meeting_url', 'participants'])) {
                        $meeting->{$field} = $value;
                    }
                }

                $meeting->updated_at = now();
                $meeting->save();

                DB::commit();

                // Load relationships for response
                $meeting->load(['user', 'tenant', 'participants']);

                // Record successful update
                $this->monitoringService->recordMetric('graphql.meeting.updated', 1, [
                    'meeting_id' => $meeting->id,
                    'user_id' => $currentUser->id,
                    'execution_time_ms' => (microtime(true) - $startTime) * 1000,
                ]);

                // Clear relevant caches
                $this->clearMeetingCaches($meeting->tenant_id);

                return $meeting;

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('graphql.meeting.update.error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Delete meeting
     */
    public function deleteMeeting($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): bool
    {
        $startTime = microtime(true);
        
        try {
            $meetingId = $args['id'];
            $currentUser = Auth::user();

            // Security validation
            $this->securityService->validateRequest($context->request());
            
            $meeting = Meeting::findOrFail($meetingId);

            // Authorization check
            if (!$this->securityService->canDeleteMeeting($currentUser, $meeting)) {
                throw new \Exception('Insufficient permissions to delete this meeting');
            }

            DB::beginTransaction();

            try {
                // Soft delete related records
                $meeting->recordings()->delete();
                $meeting->transcripts()->delete();
                $meeting->actionItems()->delete();
                $meeting->insights()->delete();

                // Delete the meeting
                $meeting->delete();

                DB::commit();

                // Record successful deletion
                $this->monitoringService->recordMetric('graphql.meeting.deleted', 1, [
                    'meeting_id' => $meetingId,
                    'user_id' => $currentUser->id,
                    'execution_time_ms' => (microtime(true) - $startTime) * 1000,
                ]);

                // Clear relevant caches
                $this->clearMeetingCaches($meeting->tenant_id);

                return true;

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('graphql.meeting.delete.error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Start meeting and update status
     */
    public function startMeeting($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Meeting
    {
        $startTime = microtime(true);
        
        try {
            $meetingId = $args['id'];
            $currentUser = Auth::user();

            // Security validation
            $this->securityService->validateRequest($context->request());
            
            $meeting = Meeting::findOrFail($meetingId);

            // Authorization check
            if (!$this->securityService->canStartMeeting($currentUser, $meeting)) {
                throw new \Exception('Insufficient permissions to start this meeting');
            }

            if ($meeting->status !== 'scheduled') {
                throw new \Exception('Meeting cannot be started in current status: ' . $meeting->status);
            }

            DB::beginTransaction();

            try {
                $meeting->status = 'in_progress';
                $meeting->started_at = now();
                $meeting->save();

                // Trigger Fireflies bot if configured
                if ($meeting->meeting_url) {
                    $this->firefliesService->startRecording($meeting->id);
                }

                DB::commit();

                // Load relationships for response
                $meeting->load(['user', 'tenant', 'participants']);

                // Record successful start
                $this->monitoringService->recordMetric('graphql.meeting.started', 1, [
                    'meeting_id' => $meeting->id,
                    'user_id' => $currentUser->id,
                    'execution_time_ms' => (microtime(true) - $startTime) * 1000,
                ]);

                // Broadcast meeting update
                event(new \App\Events\MeetingUpdate($meeting, 'status_changed', [
                    'old_status' => 'scheduled',
                    'new_status' => 'in_progress',
                    'started_at' => $meeting->started_at,
                ]));

                return $meeting;

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('graphql.meeting.start.error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * End meeting and update status
     */
    public function endMeeting($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Meeting
    {
        $startTime = microtime(true);
        
        try {
            $meetingId = $args['id'];
            $currentUser = Auth::user();

            // Security validation
            $this->securityService->validateRequest($context->request());
            
            $meeting = Meeting::findOrFail($meetingId);

            // Authorization check
            if (!$this->securityService->canEndMeeting($currentUser, $meeting)) {
                throw new \Exception('Insufficient permissions to end this meeting');
            }

            if ($meeting->status !== 'in_progress') {
                throw new \Exception('Meeting cannot be ended in current status: ' . $meeting->status);
            }

            DB::beginTransaction();

            try {
                $meeting->status = 'completed';
                $meeting->ended_at = now();
                
                // Calculate actual duration
                if ($meeting->started_at) {
                    $meeting->actual_duration_minutes = $meeting->started_at->diffInMinutes($meeting->ended_at);
                }

                $meeting->save();

                // Stop Fireflies recording if active
                if ($meeting->meeting_url) {
                    $this->firefliesService->stopRecording($meeting->id);
                }

                DB::commit();

                // Load relationships for response
                $meeting->load(['user', 'tenant', 'participants']);

                // Record successful end
                $this->monitoringService->recordMetric('graphql.meeting.ended', 1, [
                    'meeting_id' => $meeting->id,
                    'user_id' => $currentUser->id,
                    'duration_minutes' => $meeting->actual_duration_minutes,
                    'execution_time_ms' => (microtime(true) - $startTime) * 1000,
                ]);

                // Broadcast meeting update
                event(new \App\Events\MeetingUpdate($meeting, 'status_changed', [
                    'old_status' => 'in_progress',
                    'new_status' => 'completed',
                    'ended_at' => $meeting->ended_at,
                    'duration_minutes' => $meeting->actual_duration_minutes,
                ]));

                // Clear relevant caches
                $this->clearMeetingCaches($meeting->tenant_id);

                return $meeting;

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('graphql.meeting.end.error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Search meetings using vector similarity
     */
    public function searchMeetings($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo)
    {
        $startTime = microtime(true);
        
        try {
            $query = $args['query'];
            $filters = $args['filters'] ?? [];
            $first = $args['first'] ?? 10;
            $currentUser = Auth::user();

            // Security validation
            $this->securityService->validateRequest($context->request());
            
            // Perform vector search
            $searchResults = $this->vectorService->searchMeetings(
                query: $query,
                tenantId: $currentUser->tenant_id,
                filters: $filters,
                limit: $first
            );

            // Get meeting IDs from search results
            $meetingIds = collect($searchResults['matches'])->pluck('metadata.meeting_id')->unique();

            // Load meetings with authorization checks
            $meetings = Meeting::whereIn('id', $meetingIds)
                ->where('tenant_id', $currentUser->tenant_id)
                ->when(!$this->securityService->canViewAllMeetings($currentUser), function ($q) use ($currentUser) {
                    $q->where(function ($subQ) use ($currentUser) {
                        $subQ->where('user_id', $currentUser->id)
                             ->orWhereJsonContains('participants', ['email' => $currentUser->email]);
                    });
                })
                ->with(['user', 'participants'])
                ->get();

            // Sort by search relevance
            $sortedMeetings = $meetings->sortBy(function ($meeting) use ($meetingIds) {
                return array_search($meeting->id, $meetingIds->toArray());
            })->values();

            // Record successful search
            $this->monitoringService->recordMetric('graphql.meetings.search.success', 1, [
                'query' => $query,
                'results_count' => $sortedMeetings->count(),
                'user_id' => $currentUser->id,
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);

            return $sortedMeetings;

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('graphql.meetings.search.error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Get live meeting data for real-time features
     */
    public function liveMeeting($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): ?Meeting
    {
        $startTime = microtime(true);
        
        try {
            $meetingId = $args['meeting_id'];
            $currentUser = Auth::user();

            // Security validation
            $this->securityService->validateRequest($context->request());
            
            $meeting = Meeting::with(['user', 'tenant'])
                ->where('id', $meetingId)
                ->where('status', 'in_progress')
                ->first();

            if (!$meeting) {
                return null;
            }

            // Authorization check
            if (!$this->securityService->canViewMeeting($currentUser, $meeting)) {
                throw new \Exception('Unauthorized access to live meeting data');
            }

            // Add live participants data
            $meeting->live_participants = $this->getLiveParticipants($meetingId);
            $meeting->live_transcript = $this->getLiveTranscript($meetingId);

            // Record successful access
            $this->monitoringService->recordMetric('graphql.meeting.live.success', 1, [
                'meeting_id' => $meetingId,
                'user_id' => $currentUser->id,
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);

            return $meeting;

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('graphql.meeting.live.error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Validate meeting input data
     */
    protected function validateMeetingInput(array $input, User $user): void
    {
        // Check tenant meeting limits
        $tenant = $user->tenant;
        $monthlyLimit = $tenant->subscription_tier === 'free' ? 10 : 1000;
        
        $currentMonthMeetings = Meeting::where('tenant_id', $tenant->id)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        if ($currentMonthMeetings >= $monthlyLimit) {
            throw new \Exception('Monthly meeting limit reached for your subscription tier');
        }

        // Validate scheduled time
        $scheduledAt = Carbon::parse($input['scheduled_at']);
        if ($scheduledAt->isPast()) {
            throw new \Exception('Cannot schedule meeting in the past');
        }

        // Validate duration
        if ($input['duration_minutes'] < 5 || $input['duration_minutes'] > 480) {
            throw new \Exception('Meeting duration must be between 5 and 480 minutes');
        }

        // Validate participants
        if (isset($input['participants'])) {
            foreach ($input['participants'] as $participant) {
                if (!filter_var($participant['email'], FILTER_VALIDATE_EMAIL)) {
                    throw new \Exception('Invalid email address for participant: ' . $participant['email']);
                }
            }
        }
    }

    /**
     * Clear meeting-related caches
     */
    protected function clearMeetingCaches(int $tenantId): void
    {
        Cache::tags(['meetings', "tenant.{$tenantId}"])->flush();
    }

    /**
     * Get live participants for a meeting
     */
    protected function getLiveParticipants(int $meetingId): array
    {
        // This would integrate with real-time meeting platform API
        // For now, return mock data
        return [
            [
                'user_id' => null,
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'joined_at' => now()->subMinutes(5)->toISOString(),
                'is_speaking' => false,
                'audio_level' => 0.0,
                'video_enabled' => true,
                'screen_sharing' => false,
            ],
        ];
    }

    /**
     * Get live transcript for a meeting
     */
    protected function getLiveTranscript(int $meetingId): ?array
    {
        // This would integrate with Fireflies real-time API
        // For now, return mock data
        return [
            'current_speaker' => 'John Doe',
            'current_text' => 'Let me share my screen to show the quarterly results...',
            'confidence' => 0.95,
            'is_final' => false,
            'timestamp' => now()->toISOString(),
        ];
    }
}