<?php

namespace App\GraphQL\Subscriptions;

use App\Models\Meeting;
use App\Models\User;
use App\Services\AdvancedSecurityService;
use App\Services\AdvancedMonitoringService;
use App\Services\FirefliesIntegrationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Nuwave\Lighthouse\Subscriptions\Subscriber;
use Nuwave\Lighthouse\Schema\Types\GraphQLSubscription;

/**
 * Live Transcript Subscription
 * 
 * Handles real-time transcript streaming during meetings with comprehensive
 * security validation, content filtering, and performance optimization
 */
class LiveTranscript extends GraphQLSubscription
{
    protected AdvancedSecurityService $securityService;
    protected AdvancedMonitoringService $monitoringService;
    protected FirefliesIntegrationService $firefliesService;

    public function __construct(
        AdvancedSecurityService $securityService,
        AdvancedMonitoringService $monitoringService,
        FirefliesIntegrationService $firefliesService
    ) {
        $this->securityService = $securityService;
        $this->monitoringService = $monitoringService;
        $this->firefliesService = $firefliesService;
    }

    /**
     * Check if the user is authorized to receive live transcript updates
     */
    public function authorize(Subscriber $subscriber, Request $request): bool
    {
        $startTime = microtime(true);
        
        try {
            $user = Auth::user();
            
            if (!$user) {
                $this->monitoringService->recordSecurityEvent('transcript_subscription_unauthorized_anonymous', [
                    'subscription' => 'live_transcript',
                    'ip_address' => $request->ip(),
                ]);
                return false;
            }

            // Security validation with enhanced checks for transcript access
            $this->securityService->validateRequest($request);

            $meetingId = $subscriber->args['meeting_id'] ?? null;
            
            if (!$meetingId) {
                $this->monitoringService->recordSecurityEvent('transcript_subscription_missing_meeting_id', [
                    'user_id' => $user->id,
                    'subscription' => 'live_transcript',
                ]);
                return false;
            }

            // Check if meeting exists and is in progress
            $meeting = Meeting::where('id', $meetingId)
                ->where('status', 'in_progress')
                ->first();
            
            if (!$meeting) {
                $this->monitoringService->recordSecurityEvent('transcript_subscription_meeting_not_active', [
                    'user_id' => $user->id,
                    'meeting_id' => $meetingId,
                ]);
                return false;
            }

            // Enhanced authorization check for transcript access
            $authorized = $this->securityService->canViewLiveTranscript($user, $meeting);

            if ($authorized) {
                // Check transcript privacy settings
                if (!$this->checkTranscriptPrivacySettings($meeting, $user)) {
                    $this->monitoringService->recordSecurityEvent('transcript_subscription_privacy_blocked', [
                        'user_id' => $user->id,
                        'meeting_id' => $meetingId,
                        'tenant_id' => $meeting->tenant_id,
                    ]);
                    return false;
                }

                // Record successful authorization
                $this->monitoringService->recordMetric('subscription.live_transcript.authorized', 1, [
                    'user_id' => $user->id,
                    'meeting_id' => $meetingId,
                    'execution_time_ms' => (microtime(true) - $startTime) * 1000,
                ]);
                
                // Initialize transcript streaming session
                $this->initializeTranscriptSession($user, $meeting);
            } else {
                // Record authorization failure
                $this->monitoringService->recordSecurityEvent('transcript_subscription_unauthorized', [
                    'user_id' => $user->id,
                    'meeting_id' => $meetingId,
                    'tenant_id' => $meeting->tenant_id,
                    'ip_address' => $request->ip(),
                ]);
            }

            return $authorized;

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('subscription.live_transcript.authorization_error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            return false;
        }
    }

    /**
     * Filter transcript updates for specific users
     */
    public function filter(Subscriber $subscriber, $root): bool
    {
        $startTime = microtime(true);
        
        try {
            $user = $subscriber->context->user();
            $transcriptUpdate = $root;

            if (!$user || !$transcriptUpdate) {
                return false;
            }

            // Check if this update is for the subscribed meeting
            $subscribedMeetingId = $subscriber->args['meeting_id'] ?? null;
            $updateMeetingId = $transcriptUpdate['meeting_id'] ?? null;

            if ($subscribedMeetingId != $updateMeetingId) {
                return false;
            }

            // Check content filtering based on user permissions
            if (!$this->shouldDeliverTranscriptContent($transcriptUpdate, $user)) {
                return false;
            }

            // Apply real-time content moderation
            if (!$this->passesContentModeration($transcriptUpdate)) {
                $this->monitoringService->recordSecurityEvent('transcript_content_filtered', [
                    'user_id' => $user->id,
                    'meeting_id' => $updateMeetingId,
                    'reason' => 'content_moderation',
                ]);
                return false;
            }

            // Record successful filter
            $this->monitoringService->recordMetric('subscription.live_transcript.filtered.success', 1, [
                'user_id' => $user->id,
                'meeting_id' => $updateMeetingId,
                'confidence' => $transcriptUpdate['confidence'] ?? 0,
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);

            return true;

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('subscription.live_transcript.filter_error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            return false;
        }
    }

    /**
     * Transform transcript data before sending to client
     */
    public function resolve($root, array $args, $context, $resolveInfo)
    {
        $startTime = microtime(true);
        
        try {
            $user = $context->user();
            $transcriptUpdate = $root;

            // Validate transcript data structure
            if (!$this->validateTranscriptUpdate($transcriptUpdate)) {
                throw new \Exception('Invalid transcript update structure');
            }

            $meetingId = $transcriptUpdate['meeting_id'];
            $sentence = $transcriptUpdate['sentence'] ?? null;
            $isFinal = $transcriptUpdate['is_final'] ?? false;
            $speaker = $transcriptUpdate['speaker'] ?? 'Unknown';
            $confidence = $transcriptUpdate['confidence'] ?? 0;
            $timestamp = $transcriptUpdate['timestamp'] ?? now()->toISOString();

            // Apply user-specific transformations
            $resolvedData = [
                'meeting_id' => $meetingId,
                'sentence' => $this->transformSentenceData($sentence, $user),
                'is_final' => $isFinal,
                'speaker' => $this->anonymizeSpeaker($speaker, $user, $meetingId),
                'confidence' => round($confidence, 3),
                'timestamp' => $timestamp,
                'subscription_id' => $this->generateTranscriptSubscriptionId($user->id, $meetingId),
                'session_id' => $this->getTranscriptSessionId($user->id, $meetingId),
            ];

            // Add enhanced metadata for final sentences
            if ($isFinal && $sentence) {
                $resolvedData['metadata'] = [
                    'word_count' => str_word_count($sentence['text'] ?? ''),
                    'speaking_duration' => $this->calculateSpeakingDuration($sentence),
                    'sentiment_score' => $this->calculateSentimentScore($sentence['text'] ?? ''),
                    'contains_action_items' => $this->detectActionItems($sentence['text'] ?? ''),
                ];
            }

            // Add user-specific context
            $resolvedData['user_context'] = [
                'is_speaker' => $this->isUserCurrentSpeaker($user, $speaker, $meetingId),
                'speaking_time_today' => $this->getUserSpeakingTimeToday($user, $meetingId),
                'transcript_access_level' => $this->getTranscriptAccessLevel($user, $meetingId),
            ];

            // Record successful resolution
            $this->monitoringService->recordMetric('subscription.live_transcript.resolved', 1, [
                'user_id' => $user->id,
                'meeting_id' => $meetingId,
                'is_final' => $isFinal,
                'confidence' => $confidence,
                'word_count' => $resolvedData['metadata']['word_count'] ?? 0,
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);

            return $resolvedData;

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('subscription.live_transcript.resolve_error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Get subscription topic identifier
     */
    public function encodeTopic(Subscriber $subscriber, string $fieldName): string
    {
        $meetingId = $subscriber->args['meeting_id'];
        $userId = $subscriber->context->user()->id;
        
        return "live_transcript.{$meetingId}.user.{$userId}";
    }

    /**
     * Determine subscription channels
     */
    public function broadcastOnChannels(Subscriber $subscriber): array
    {
        $meetingId = $subscriber->args['meeting_id'];
        $user = $subscriber->context->user();
        
        return [
            // Meeting-specific transcript channel
            "meeting.{$meetingId}.transcript",
            
            // User-specific transcript channel for personalized content
            "user.{$user->id}.transcript.{$meetingId}",
            
            // Tenant-specific channel for compliance monitoring
            "tenant.{$user->tenant_id}.transcript_monitoring",
        ];
    }

    /**
     * Check transcript privacy settings
     */
    protected function checkTranscriptPrivacySettings(Meeting $meeting, User $user): bool
    {
        $tenantSettings = $meeting->tenant->settings ?? [];
        $meetingSettings = $meeting->settings ?? [];
        
        // Check if transcripts are enabled for this tenant
        if (!($tenantSettings['transcripts_enabled'] ?? true)) {
            return false;
        }

        // Check meeting-specific transcript settings
        if (!($meetingSettings['live_transcript_enabled'] ?? true)) {
            return false;
        }

        // Check user role permissions
        $allowedRoles = $meetingSettings['transcript_allowed_roles'] ?? ['admin', 'manager', 'user'];
        if (!in_array($user->role, $allowedRoles)) {
            return false;
        }

        return true;
    }

    /**
     * Initialize transcript streaming session
     */
    protected function initializeTranscriptSession(User $user, Meeting $meeting): void
    {
        $sessionId = $this->generateSessionId($user->id, $meeting->id);
        $sessionData = [
            'user_id' => $user->id,
            'meeting_id' => $meeting->id,
            'started_at' => now()->toISOString(),
            'last_activity' => now()->toISOString(),
            'total_words_received' => 0,
            'confidence_average' => 0,
        ];

        Cache::put("transcript_session.{$sessionId}", $sessionData, 14400); // 4 hours

        // Record session start
        $this->monitoringService->recordMetric('transcript_session.started', 1, [
            'user_id' => $user->id,
            'meeting_id' => $meeting->id,
            'session_id' => $sessionId,
        ]);
    }

    /**
     * Check if transcript content should be delivered to user
     */
    protected function shouldDeliverTranscriptContent(array $transcriptUpdate, User $user): bool
    {
        $confidence = $transcriptUpdate['confidence'] ?? 0;
        $isFinal = $transcriptUpdate['is_final'] ?? false;
        
        // User preference for minimum confidence threshold
        $userSettings = $user->settings ?? [];
        $minConfidence = $userSettings['min_transcript_confidence'] ?? 0.7;
        
        // For final sentences, be more lenient with confidence
        $effectiveMinConfidence = $isFinal ? ($minConfidence * 0.8) : $minConfidence;
        
        return $confidence >= $effectiveMinConfidence;
    }

    /**
     * Apply real-time content moderation
     */
    protected function passesContentModeration(array $transcriptUpdate): bool
    {
        $sentence = $transcriptUpdate['sentence'] ?? null;
        if (!$sentence || !isset($sentence['text'])) {
            return true;
        }

        $text = $sentence['text'];
        
        // Check for prohibited content patterns
        $prohibitedPatterns = [
            '/\b(password|secret|confidential)\s*[:=]\s*\S+/i',
            '/\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b/', // Credit card patterns
            '/\b\d{3}-\d{2}-\d{4}\b/', // SSN patterns
        ];

        foreach ($prohibitedPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Transform sentence data based on user permissions
     */
    protected function transformSentenceData(?array $sentence, User $user): ?array
    {
        if (!$sentence) {
            return null;
        }

        $transformed = $sentence;
        
        // Apply content filtering based on user role
        if ($user->role === 'viewer') {
            // Remove speaker identification for viewers
            $transformed['speaker'] = 'Participant';
        }

        // Apply PII redaction if enabled
        $userSettings = $user->settings ?? [];
        if ($userSettings['redact_pii'] ?? false) {
            $transformed['text'] = $this->redactPII($transformed['text'] ?? '');
        }

        return $transformed;
    }

    /**
     * Anonymize speaker based on user permissions
     */
    protected function anonymizeSpeaker(string $speaker, User $user, int $meetingId): string
    {
        $userSettings = $user->settings ?? [];
        
        // Check if speaker anonymization is enabled
        if ($userSettings['anonymize_speakers'] ?? false) {
            return 'Participant ' . $this->getSpeakerHash($speaker, $meetingId);
        }

        return $speaker;
    }

    /**
     * Generate speaker hash for anonymization
     */
    protected function getSpeakerHash(string $speaker, int $meetingId): string
    {
        $hash = hash('sha256', $speaker . $meetingId);
        return substr($hash, 0, 8);
    }

    /**
     * Calculate speaking duration from sentence data
     */
    protected function calculateSpeakingDuration(?array $sentence): float
    {
        if (!$sentence) {
            return 0.0;
        }

        $startTime = $sentence['start_time'] ?? 0;
        $endTime = $sentence['end_time'] ?? 0;
        
        return max(0, $endTime - $startTime);
    }

    /**
     * Calculate sentiment score for text
     */
    protected function calculateSentimentScore(string $text): float
    {
        // Simplified sentiment analysis
        $positiveWords = ['good', 'great', 'excellent', 'perfect', 'amazing', 'wonderful'];
        $negativeWords = ['bad', 'terrible', 'awful', 'horrible', 'disappointing', 'frustrating'];
        
        $words = str_word_count(strtolower($text), 1);
        $positiveCount = count(array_intersect($words, $positiveWords));
        $negativeCount = count(array_intersect($words, $negativeWords));
        
        $totalSentimentWords = $positiveCount + $negativeCount;
        
        if ($totalSentimentWords === 0) {
            return 0.0; // Neutral
        }
        
        return ($positiveCount - $negativeCount) / $totalSentimentWords;
    }

    /**
     * Detect action items in text
     */
    protected function detectActionItems(string $text): bool
    {
        $actionPatterns = [
            '/\b(will|should|need to|must|have to|action)\s+\w+/i',
            '/\b(todo|task|follow up|next step)\b/i',
            '/\b(by\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday|tomorrow|next week))\b/i',
        ];

        foreach ($actionPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user is currently speaking
     */
    protected function isUserCurrentSpeaker(User $user, string $speaker, int $meetingId): bool
    {
        // Compare with user's name or email
        return strcasecmp($speaker, $user->name) === 0 || 
               strcasecmp($speaker, $user->email) === 0;
    }

    /**
     * Get user's speaking time for today
     */
    protected function getUserSpeakingTimeToday(User $user, int $meetingId): float
    {
        $cacheKey = "user_speaking_time.{$user->id}.{$meetingId}." . now()->format('Y-m-d');
        
        return Cache::get($cacheKey, 0.0);
    }

    /**
     * Get transcript access level for user
     */
    protected function getTranscriptAccessLevel(User $user, int $meetingId): string
    {
        if ($user->role === 'admin') {
            return 'full';
        } elseif ($user->role === 'manager') {
            return 'standard';
        } else {
            return 'basic';
        }
    }

    /**
     * Validate transcript update structure
     */
    protected function validateTranscriptUpdate(array $transcriptUpdate): bool
    {
        $requiredFields = ['meeting_id', 'confidence', 'timestamp'];
        
        foreach ($requiredFields as $field) {
            if (!isset($transcriptUpdate[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Generate transcript subscription ID
     */
    protected function generateTranscriptSubscriptionId(int $userId, int $meetingId): string
    {
        return hash('sha256', "transcript_sub_{$userId}_{$meetingId}_" . now()->timestamp);
    }

    /**
     * Get transcript session ID
     */
    protected function getTranscriptSessionId(int $userId, int $meetingId): string
    {
        return $this->generateSessionId($userId, $meetingId);
    }

    /**
     * Generate session ID
     */
    protected function generateSessionId(int $userId, int $meetingId): string
    {
        return hash('sha256', "session_{$userId}_{$meetingId}_" . now()->format('Y-m-d'));
    }

    /**
     * Redact PII from text
     */
    protected function redactPII(string $text): string
    {
        // Email redaction
        $text = preg_replace('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', '[EMAIL]', $text);
        
        // Phone number redaction
        $text = preg_replace('/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/', '[PHONE]', $text);
        
        // SSN redaction
        $text = preg_replace('/\b\d{3}-\d{2}-\d{4}\b/', '[SSN]', $text);
        
        return $text;
    }

    /**
     * Handle subscription lifecycle events
     */
    public function onSubscribe(Subscriber $subscriber): void
    {
        $user = $subscriber->context->user();
        $meetingId = $subscriber->args['meeting_id'];
        
        // Record subscription start
        $this->monitoringService->recordMetric('subscription.live_transcript.subscribed', 1, [
            'user_id' => $user->id,
            'meeting_id' => $meetingId,
            'timestamp' => now()->toISOString(),
        ]);

        // Update active transcript subscribers count
        $this->updateActiveTranscriptSubscribers($meetingId, 1);
    }

    /**
     * Handle subscription cleanup
     */
    public function onUnsubscribe(Subscriber $subscriber): void
    {
        $user = $subscriber->context->user();
        $meetingId = $subscriber->args['meeting_id'];
        
        // Record subscription end
        $this->monitoringService->recordMetric('subscription.live_transcript.unsubscribed', 1, [
            'user_id' => $user->id,
            'meeting_id' => $meetingId,
            'timestamp' => now()->toISOString(),
        ]);

        // Clean up session data
        $sessionId = $this->generateSessionId($user->id, $meetingId);
        Cache::forget("transcript_session.{$sessionId}");

        // Update active transcript subscribers count
        $this->updateActiveTranscriptSubscribers($meetingId, -1);
    }

    /**
     * Update active transcript subscribers count
     */
    protected function updateActiveTranscriptSubscribers(int $meetingId, int $delta): void
    {
        $key = "meeting.{$meetingId}.transcript_subscribers";
        $current = cache()->get($key, 0);
        $new = max(0, $current + $delta);
        
        cache()->put($key, $new, 3600);
        
        // Record metric
        $this->monitoringService->recordMetric('subscription.live_transcript.active_count', $new, [
            'meeting_id' => $meetingId,
        ]);
    }
}