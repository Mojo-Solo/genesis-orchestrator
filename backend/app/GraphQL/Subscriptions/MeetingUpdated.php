<?php

namespace App\GraphQL\Subscriptions;

use App\Models\Meeting;
use App\Models\User;
use App\Services\AdvancedSecurityService;
use App\Services\AdvancedMonitoringService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Nuwave\Lighthouse\Subscriptions\Subscriber;
use Nuwave\Lighthouse\Schema\Types\GraphQLSubscription;
use Pusher\Pusher;

/**
 * Meeting Updated Subscription
 * 
 * Handles real-time meeting updates with comprehensive security validation,
 * authorization checks, and performance monitoring
 */
class MeetingUpdated extends GraphQLSubscription
{
    protected AdvancedSecurityService $securityService;
    protected AdvancedMonitoringService $monitoringService;

    public function __construct(
        AdvancedSecurityService $securityService,
        AdvancedMonitoringService $monitoringService
    ) {
        $this->securityService = $securityService;
        $this->monitoringService = $monitoringService;
    }

    /**
     * Check if the user is authorized to receive this subscription
     */
    public function authorize(Subscriber $subscriber, Request $request): bool
    {
        $startTime = microtime(true);
        
        try {
            $user = Auth::user();
            
            if (!$user) {
                $this->monitoringService->recordSecurityEvent('subscription_unauthorized_anonymous', [
                    'subscription' => 'meeting_updated',
                    'ip_address' => $request->ip(),
                ]);
                return false;
            }

            // Security validation
            $this->securityService->validateRequest($request);

            $meetingId = $subscriber->args['meeting_id'] ?? null;
            
            if (!$meetingId) {
                $this->monitoringService->recordSecurityEvent('subscription_missing_meeting_id', [
                    'user_id' => $user->id,
                    'subscription' => 'meeting_updated',
                ]);
                return false;
            }

            // Check if user can access this meeting
            $meeting = Meeting::find($meetingId);
            
            if (!$meeting) {
                $this->monitoringService->recordSecurityEvent('subscription_meeting_not_found', [
                    'user_id' => $user->id,
                    'meeting_id' => $meetingId,
                ]);
                return false;
            }

            $authorized = $this->securityService->canViewMeeting($user, $meeting);

            if ($authorized) {
                // Record successful authorization
                $this->monitoringService->recordMetric('subscription.meeting_updated.authorized', 1, [
                    'user_id' => $user->id,
                    'meeting_id' => $meetingId,
                    'execution_time_ms' => (microtime(true) - $startTime) * 1000,
                ]);
            } else {
                // Record authorization failure
                $this->monitoringService->recordSecurityEvent('subscription_meeting_unauthorized', [
                    'user_id' => $user->id,
                    'meeting_id' => $meetingId,
                    'tenant_id' => $meeting->tenant_id,
                    'ip_address' => $request->ip(),
                ]);
            }

            return $authorized;

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('subscription.meeting_updated.authorization_error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            return false;
        }
    }

    /**
     * Filter which users should receive this subscription update
     */
    public function filter(Subscriber $subscriber, $root): bool
    {
        $startTime = microtime(true);
        
        try {
            $user = $subscriber->context->user();
            $meetingUpdate = $root;
            $meeting = $meetingUpdate->meeting ?? $meetingUpdate['meeting'] ?? null;

            if (!$user || !$meeting) {
                return false;
            }

            // Check if this update is for the subscribed meeting
            $subscribedMeetingId = $subscriber->args['meeting_id'] ?? null;
            $updateMeetingId = is_object($meeting) ? $meeting->id : ($meeting['id'] ?? null);

            if ($subscribedMeetingId != $updateMeetingId) {
                return false;
            }

            // Additional authorization check
            $authorized = $this->securityService->canViewMeeting($user, $meeting);

            if ($authorized) {
                // Record successful filter
                $this->monitoringService->recordMetric('subscription.meeting_updated.filtered.success', 1, [
                    'user_id' => $user->id,
                    'meeting_id' => $updateMeetingId,
                    'execution_time_ms' => (microtime(true) - $startTime) * 1000,
                ]);
            } else {
                // Record filter rejection
                $this->monitoringService->recordSecurityEvent('subscription_filter_rejected', [
                    'user_id' => $user->id,
                    'meeting_id' => $updateMeetingId,
                    'subscription' => 'meeting_updated',
                ]);
            }

            return $authorized;

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('subscription.meeting_updated.filter_error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            return false;
        }
    }

    /**
     * Transform the subscription data before sending to client
     */
    public function resolve($root, array $args, $context, $resolveInfo)
    {
        $startTime = microtime(true);
        
        try {
            $user = $context->user();
            $meetingUpdate = $root;

            // Ensure we have the required data structure
            if (!isset($meetingUpdate['meeting']) || !isset($meetingUpdate['type'])) {
                throw new \Exception('Invalid meeting update structure');
            }

            $meeting = $meetingUpdate['meeting'];
            $updateType = $meetingUpdate['type'];
            $updateData = $meetingUpdate['data'] ?? [];

            // Add additional context for the client
            $resolvedData = [
                'meeting' => $this->transformMeetingData($meeting, $user),
                'type' => $updateType,
                'data' => $updateData,
                'timestamp' => now()->toISOString(),
                'subscription_id' => $this->generateSubscriptionId($user->id, $meeting['id'] ?? $meeting->id),
            ];

            // Add user-specific data based on update type
            switch ($updateType) {
                case 'participant_joined':
                case 'participant_left':
                    $resolvedData['participants_count'] = $this->getParticipantsCount($meeting);
                    break;
                    
                case 'transcript_updated':
                    $resolvedData['transcript_confidence'] = $updateData['confidence'] ?? 0;
                    break;
                    
                case 'recording_started':
                case 'recording_stopped':
                    $resolvedData['recording_status'] = $updateData['status'] ?? 'unknown';
                    break;
            }

            // Record successful resolution
            $this->monitoringService->recordMetric('subscription.meeting_updated.resolved', 1, [
                'user_id' => $user->id,
                'meeting_id' => $meeting['id'] ?? $meeting->id,
                'update_type' => $updateType,
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);

            return $resolvedData;

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('subscription.meeting_updated.resolve_error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Get the subscription identifier
     */
    public function encodeTopic(Subscriber $subscriber, string $fieldName): string
    {
        $meetingId = $subscriber->args['meeting_id'];
        $userId = $subscriber->context->user()->id;
        
        // Create unique topic that includes meeting and user context
        return "meeting_updated.{$meetingId}.user.{$userId}";
    }

    /**
     * Determine subscription channels
     */
    public function broadcastOnChannels(Subscriber $subscriber): array
    {
        $meetingId = $subscriber->args['meeting_id'];
        $user = $subscriber->context->user();
        
        return [
            // Meeting-specific channel
            "meeting.{$meetingId}.updates",
            
            // Tenant-specific channel for cross-meeting updates
            "tenant.{$user->tenant_id}.meeting_updates",
            
            // User-specific channel for personalized updates
            "user.{$user->id}.meeting_updates",
        ];
    }

    /**
     * Transform meeting data for client consumption
     */
    protected function transformMeetingData($meeting, User $user): array
    {
        $meetingArray = is_object($meeting) ? $meeting->toArray() : $meeting;
        
        // Remove sensitive data based on user permissions
        if (!$this->securityService->canViewMeetingDetails($user, $meeting)) {
            unset($meetingArray['meeting_url']);
            unset($meetingArray['participants']);
        }

        // Add computed fields
        $meetingArray['is_live'] = ($meetingArray['status'] ?? '') === 'in_progress';
        $meetingArray['user_is_participant'] = $this->isUserParticipant($meetingArray, $user);
        $meetingArray['user_is_host'] = ($meetingArray['user_id'] ?? null) === $user->id;

        return $meetingArray;
    }

    /**
     * Check if user is a participant in the meeting
     */
    protected function isUserParticipant(array $meeting, User $user): bool
    {
        $participants = $meeting['participants'] ?? [];
        
        foreach ($participants as $participant) {
            if (($participant['email'] ?? '') === $user->email) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get current participants count
     */
    protected function getParticipantsCount($meeting): int
    {
        $participants = is_object($meeting) ? $meeting->participants : ($meeting['participants'] ?? []);
        return is_array($participants) ? count($participants) : 0;
    }

    /**
     * Generate unique subscription ID
     */
    protected function generateSubscriptionId(int $userId, int $meetingId): string
    {
        return hash('sha256', "meeting_sub_{$userId}_{$meetingId}_" . now()->timestamp);
    }

    /**
     * Handle subscription lifecycle events
     */
    public function onSubscribe(Subscriber $subscriber): void
    {
        $user = $subscriber->context->user();
        $meetingId = $subscriber->args['meeting_id'];
        
        // Record subscription start
        $this->monitoringService->recordMetric('subscription.meeting_updated.subscribed', 1, [
            'user_id' => $user->id,
            'meeting_id' => $meetingId,
            'timestamp' => now()->toISOString(),
        ]);

        // Update active subscribers count
        $this->updateActiveSubscribersCount($meetingId, 1);
    }

    /**
     * Handle subscription cleanup
     */
    public function onUnsubscribe(Subscriber $subscriber): void
    {
        $user = $subscriber->context->user();
        $meetingId = $subscriber->args['meeting_id'];
        
        // Record subscription end
        $this->monitoringService->recordMetric('subscription.meeting_updated.unsubscribed', 1, [
            'user_id' => $user->id,
            'meeting_id' => $meetingId,
            'timestamp' => now()->toISOString(),
        ]);

        // Update active subscribers count
        $this->updateActiveSubscribersCount($meetingId, -1);
    }

    /**
     * Update active subscribers count for monitoring
     */
    protected function updateActiveSubscribersCount(int $meetingId, int $delta): void
    {
        $key = "meeting.{$meetingId}.active_subscribers";
        $current = cache()->get($key, 0);
        $new = max(0, $current + $delta);
        
        cache()->put($key, $new, 3600); // Cache for 1 hour
        
        // Record metric
        $this->monitoringService->recordMetric('subscription.meeting_updated.active_count', $new, [
            'meeting_id' => $meetingId,
        ]);
    }

    /**
     * Handle subscription errors
     */
    public function onError(\Exception $error, Subscriber $subscriber): void
    {
        $user = $subscriber->context->user();
        $meetingId = $subscriber->args['meeting_id'] ?? 'unknown';
        
        // Record subscription error
        $this->monitoringService->recordMetric('subscription.meeting_updated.error', 1, [
            'user_id' => $user?->id,
            'meeting_id' => $meetingId,
            'error' => $error->getMessage(),
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Validate subscription arguments
     */
    public function validateArgs(array $args): bool
    {
        if (!isset($args['meeting_id']) || !is_numeric($args['meeting_id'])) {
            return false;
        }

        // Additional validation can be added here
        return true;
    }

    /**
     * Get subscription rate limit
     */
    public function getRateLimit(Subscriber $subscriber): ?array
    {
        $user = $subscriber->context->user();
        
        // Rate limit based on user tier
        $limits = [
            'free' => ['max_requests' => 100, 'window_seconds' => 3600],
            'starter' => ['max_requests' => 500, 'window_seconds' => 3600],
            'professional' => ['max_requests' => 2000, 'window_seconds' => 3600],
            'enterprise' => ['max_requests' => 10000, 'window_seconds' => 3600],
        ];

        $tier = $user->tenant->subscription_tier ?? 'free';
        
        return $limits[$tier] ?? $limits['free'];
    }
}