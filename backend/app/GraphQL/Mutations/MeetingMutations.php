<?php

namespace App\GraphQL\Mutations;

use App\Models\Meeting;
use App\Models\Recording;
use App\Models\ActionItem;
use App\Models\Insight;
use App\Services\AdvancedSecurityService;
use App\Services\AdvancedMonitoringService;
use App\Services\FirefliesIntegrationService;
use App\Services\PineconeVectorService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Carbon\Carbon;

/**
 * Meeting GraphQL Mutations
 * 
 * Handles all meeting-related data modifications with comprehensive
 * validation, security, monitoring, and AI integration
 */
class MeetingMutations
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
     * Upload and process meeting recording
     */
    public function uploadRecording($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Recording
    {
        $startTime = microtime(true);
        
        try {
            $input = $args['input'];
            $currentUser = Auth::user();

            // Security validation
            $this->securityService->validateRequest($context->request());
            
            $meetingId = $input['meeting_id'];
            $file = $input['file'];
            $format = $input['format'];
            $duration = $input['duration'];

            // Load and validate meeting
            $meeting = Meeting::findOrFail($meetingId);
            
            // Authorization check
            if (!$this->securityService->canUploadRecording($currentUser, $meeting)) {
                throw new \Exception('Insufficient permissions to upload recording for this meeting');
            }

            // Validate file upload
            $this->validateRecordingFile($file, $format, $duration, $currentUser);

            DB::beginTransaction();

            try {
                // Store file securely
                $filePath = $this->storeRecordingFile($file, $meeting, $currentUser);

                // Create recording record
                $recording = new Recording([
                    'meeting_id' => $meetingId,
                    'filename' => $file->getClientOriginalName(),
                    'file_path' => $filePath,
                    'format' => $format,
                    'duration' => $duration,
                    'file_size' => $file->getSize(),
                    'upload_status' => 'completed',
                    'processing_status' => 'pending',
                ]);

                $recording->save();

                // Queue processing job
                $this->queueRecordingProcessing($recording, $meeting);

                DB::commit();

                // Load relationships for response
                $recording->load(['meeting']);

                // Record successful upload
                $this->monitoringService->recordMetric('recording.uploaded', 1, [
                    'recording_id' => $recording->id,
                    'meeting_id' => $meetingId,
                    'user_id' => $currentUser->id,
                    'file_size_mb' => round($file->getSize() / 1024 / 1024, 2),
                    'duration_minutes' => $duration,
                    'format' => $format,
                    'execution_time_ms' => (microtime(true) - $startTime) * 1000,
                ]);

                return $recording;

            } catch (\Exception $e) {
                DB::rollBack();
                
                // Clean up uploaded file on error
                if (isset($filePath)) {
                    Storage::delete($filePath);
                }
                
                throw $e;
            }

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('recording.upload.error', 1, [
                'error' => $e->getMessage(),
                'user_id' => $currentUser?->id,
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Process meeting recording (trigger AI analysis)
     */
    public function processRecording($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): bool
    {
        $startTime = microtime(true);
        
        try {
            $recordingId = $args['recording_id'];
            $currentUser = Auth::user();

            // Security validation
            $this->securityService->validateRequest($context->request());
            
            $recording = Recording::with('meeting')->findOrFail($recordingId);
            
            // Authorization check
            if (!$this->securityService->canProcessRecording($currentUser, $recording)) {
                throw new \Exception('Insufficient permissions to process this recording');
            }

            if ($recording->processing_status !== 'pending') {
                throw new \Exception('Recording is not in pending status for processing');
            }

            DB::beginTransaction();

            try {
                // Update processing status
                $recording->processing_status = 'processing';
                $recording->save();

                // Submit to Fireflies for processing
                $result = $this->firefliesService->uploadRecording([
                    'file_path' => $recording->file_path,
                    'filename' => $recording->filename,
                    'meeting_title' => $recording->meeting->title,
                    'metadata' => [
                        'recording_id' => $recording->id,
                        'meeting_id' => $recording->meeting_id,
                        'tenant_id' => $recording->meeting->tenant_id,
                        'user_id' => $currentUser->id,
                    ],
                ]);

                // Store Fireflies processing ID
                $recording->fireflies_id = $result['id'] ?? null;
                $recording->save();

                DB::commit();

                // Record successful processing initiation
                $this->monitoringService->recordMetric('recording.processing.started', 1, [
                    'recording_id' => $recording->id,
                    'meeting_id' => $recording->meeting_id,
                    'user_id' => $currentUser->id,
                    'fireflies_id' => $recording->fireflies_id,
                    'execution_time_ms' => (microtime(true) - $startTime) * 1000,
                ]);

                return true;

            } catch (\Exception $e) {
                DB::rollBack();
                
                // Reset processing status on error
                $recording->processing_status = 'failed';
                $recording->save();
                
                throw $e;
            }

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('recording.processing.error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Process meeting transcript (trigger AI analysis)
     */
    public function processMeetingTranscript($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): bool
    {
        $startTime = microtime(true);
        
        try {
            $meetingId = $args['meeting_id'];
            $currentUser = Auth::user();

            // Security validation
            $this->securityService->validateRequest($context->request());
            
            $meeting = Meeting::with(['transcripts'])->findOrFail($meetingId);
            
            // Authorization check
            if (!$this->securityService->canProcessTranscript($currentUser, $meeting)) {
                throw new \Exception('Insufficient permissions to process transcript for this meeting');
            }

            if ($meeting->transcripts->isEmpty()) {
                throw new \Exception('No transcripts available for processing');
            }

            DB::beginTransaction();

            try {
                // Update transcript processing status
                foreach ($meeting->transcripts as $transcript) {
                    if ($transcript->processing_status === 'completed') {
                        $transcript->processing_status = 'processing';
                        $transcript->save();
                    }
                }

                // Generate AI insights
                $insights = $this->generateMeetingInsights($meeting);

                // Process each insight
                foreach ($insights as $insightData) {
                    $insight = new Insight([
                        'meeting_id' => $meetingId,
                        'type' => $insightData['type'],
                        'title' => $insightData['title'],
                        'content' => $insightData['content'],
                        'confidence_score' => $insightData['confidence_score'],
                        'metadata' => $insightData['metadata'] ?? [],
                        'tags' => $insightData['tags'] ?? [],
                    ]);
                    $insight->save();
                }

                // Create vector embeddings for search
                $this->createTranscriptEmbeddings($meeting);

                // Update transcript processing status
                foreach ($meeting->transcripts as $transcript) {
                    $transcript->processing_status = 'completed';
                    $transcript->save();
                }

                DB::commit();

                // Record successful processing
                $this->monitoringService->recordMetric('transcript.processing.completed', 1, [
                    'meeting_id' => $meetingId,
                    'user_id' => $currentUser->id,
                    'insights_generated' => count($insights),
                    'execution_time_ms' => (microtime(true) - $startTime) * 1000,
                ]);

                return true;

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('transcript.processing.error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Create action item
     */
    public function createActionItem($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): ActionItem
    {
        $startTime = microtime(true);
        
        try {
            $input = $args['input'];
            $currentUser = Auth::user();

            // Security validation
            $this->securityService->validateRequest($context->request());
            
            $meetingId = $input['meeting_id'];
            $meeting = Meeting::findOrFail($meetingId);
            
            // Authorization check
            if (!$this->securityService->canCreateActionItem($currentUser, $meeting)) {
                throw new \Exception('Insufficient permissions to create action items for this meeting');
            }

            // Validate action item data
            $this->validateActionItemInput($input);

            DB::beginTransaction();

            try {
                $actionItem = new ActionItem([
                    'meeting_id' => $meetingId,
                    'title' => $input['title'],
                    'description' => $input['description'] ?? null,
                    'assignee_name' => $input['assignee_name'] ?? null,
                    'assignee_email' => $input['assignee_email'] ?? null,
                    'due_date' => isset($input['due_date']) ? Carbon::parse($input['due_date']) : null,
                    'priority' => $input['priority'],
                    'status' => 'pending',
                    'confidence_score' => 1.0, // Manual creation has high confidence
                    'extracted_from' => 'manual',
                ]);

                $actionItem->save();

                // Create vector embedding for action item
                $this->createActionItemEmbedding($actionItem, $meeting);

                // Send notification if assignee specified
                if ($actionItem->assignee_email) {
                    $this->sendActionItemNotification($actionItem);
                }

                DB::commit();

                // Load relationships for response
                $actionItem->load(['meeting']);

                // Record successful creation
                $this->monitoringService->recordMetric('action_item.created', 1, [
                    'action_item_id' => $actionItem->id,
                    'meeting_id' => $meetingId,
                    'user_id' => $currentUser->id,
                    'priority' => $actionItem->priority,
                    'has_assignee' => !empty($actionItem->assignee_email),
                    'has_due_date' => !empty($actionItem->due_date),
                    'execution_time_ms' => (microtime(true) - $startTime) * 1000,
                ]);

                return $actionItem;

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('action_item.create.error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Update action item
     */
    public function updateActionItem($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): ActionItem
    {
        $startTime = microtime(true);
        
        try {
            $actionItemId = $args['id'];
            $input = $args['input'];
            $currentUser = Auth::user();

            // Security validation
            $this->securityService->validateRequest($context->request());
            
            $actionItem = ActionItem::with('meeting')->findOrFail($actionItemId);
            
            // Authorization check
            if (!$this->securityService->canUpdateActionItem($currentUser, $actionItem)) {
                throw new \Exception('Insufficient permissions to update this action item');
            }

            $oldStatus = $actionItem->status;

            DB::beginTransaction();

            try {
                // Update fields
                foreach ($input as $field => $value) {
                    if (in_array($field, ['title', 'description', 'assignee_name', 'assignee_email', 'priority', 'status'])) {
                        $actionItem->{$field} = $value;
                    } elseif ($field === 'due_date' && $value) {
                        $actionItem->due_date = Carbon::parse($value);
                    }
                }

                $actionItem->updated_at = now();
                $actionItem->save();

                // Update vector embedding if content changed
                if (isset($input['title']) || isset($input['description'])) {
                    $this->updateActionItemEmbedding($actionItem);
                }

                // Send notification if status changed to completed
                if ($oldStatus !== 'completed' && $actionItem->status === 'completed') {
                    $this->sendActionItemCompletionNotification($actionItem);
                }

                DB::commit();

                // Load relationships for response
                $actionItem->load(['meeting']);

                // Record successful update
                $this->monitoringService->recordMetric('action_item.updated', 1, [
                    'action_item_id' => $actionItem->id,
                    'meeting_id' => $actionItem->meeting_id,
                    'user_id' => $currentUser->id,
                    'old_status' => $oldStatus,
                    'new_status' => $actionItem->status,
                    'execution_time_ms' => (microtime(true) - $startTime) * 1000,
                ]);

                return $actionItem;

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('action_item.update.error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Complete action item (shortcut for status update)
     */
    public function completeActionItem($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): ActionItem
    {
        $startTime = microtime(true);
        
        try {
            $actionItemId = $args['id'];
            $currentUser = Auth::user();

            // Security validation
            $this->securityService->validateRequest($context->request());
            
            $actionItem = ActionItem::with('meeting')->findOrFail($actionItemId);
            
            // Authorization check
            if (!$this->securityService->canCompleteActionItem($currentUser, $actionItem)) {
                throw new \Exception('Insufficient permissions to complete this action item');
            }

            if ($actionItem->status === 'completed') {
                throw new \Exception('Action item is already completed');
            }

            $oldStatus = $actionItem->status;

            DB::beginTransaction();

            try {
                $actionItem->status = 'completed';
                $actionItem->completed_at = now();
                $actionItem->completed_by = $currentUser->id;
                $actionItem->save();

                // Send completion notification
                $this->sendActionItemCompletionNotification($actionItem);

                DB::commit();

                // Load relationships for response
                $actionItem->load(['meeting']);

                // Record successful completion
                $this->monitoringService->recordMetric('action_item.completed', 1, [
                    'action_item_id' => $actionItem->id,
                    'meeting_id' => $actionItem->meeting_id,
                    'user_id' => $currentUser->id,
                    'completion_time_hours' => $actionItem->created_at->diffInHours($actionItem->completed_at),
                    'execution_time_ms' => (microtime(true) - $startTime) * 1000,
                ]);

                return $actionItem;

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('action_item.complete.error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Delete action item
     */
    public function deleteActionItem($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): bool
    {
        $startTime = microtime(true);
        
        try {
            $actionItemId = $args['id'];
            $currentUser = Auth::user();

            // Security validation
            $this->securityService->validateRequest($context->request());
            
            $actionItem = ActionItem::with('meeting')->findOrFail($actionItemId);
            
            // Authorization check
            if (!$this->securityService->canDeleteActionItem($currentUser, $actionItem)) {
                throw new \Exception('Insufficient permissions to delete this action item');
            }

            DB::beginTransaction();

            try {
                // Remove vector embedding
                $this->removeActionItemEmbedding($actionItem);

                // Soft delete the action item
                $actionItem->delete();

                DB::commit();

                // Record successful deletion
                $this->monitoringService->recordMetric('action_item.deleted', 1, [
                    'action_item_id' => $actionItemId,
                    'meeting_id' => $actionItem->meeting_id,
                    'user_id' => $currentUser->id,
                    'execution_time_ms' => (microtime(true) - $startTime) * 1000,
                ]);

                return true;

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('action_item.delete.error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Generate meeting insights using AI
     */
    public function generateMeetingInsights($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        $startTime = microtime(true);
        
        try {
            $meetingId = $args['meeting_id'];
            $currentUser = Auth::user();

            // Security validation
            $this->securityService->validateRequest($context->request());
            
            $meeting = Meeting::with(['transcripts'])->findOrFail($meetingId);
            
            // Authorization check
            if (!$this->securityService->canGenerateInsights($currentUser, $meeting)) {
                throw new \Exception('Insufficient permissions to generate insights for this meeting');
            }

            if ($meeting->transcripts->isEmpty()) {
                throw new \Exception('No transcripts available for insight generation');
            }

            $insights = $this->generateMeetingInsights($meeting);

            DB::beginTransaction();

            try {
                $savedInsights = [];

                foreach ($insights as $insightData) {
                    $insight = new Insight([
                        'meeting_id' => $meetingId,
                        'type' => $insightData['type'],
                        'title' => $insightData['title'],
                        'content' => $insightData['content'],
                        'confidence_score' => $insightData['confidence_score'],
                        'metadata' => $insightData['metadata'] ?? [],
                        'tags' => $insightData['tags'] ?? [],
                    ]);
                    $insight->save();
                    $savedInsights[] = $insight;
                }

                DB::commit();

                // Record successful generation
                $this->monitoringService->recordMetric('insights.generated', 1, [
                    'meeting_id' => $meetingId,
                    'user_id' => $currentUser->id,
                    'insights_count' => count($savedInsights),
                    'execution_time_ms' => (microtime(true) - $startTime) * 1000,
                ]);

                return $savedInsights;

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('insights.generate.error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Validate recording file upload
     */
    protected function validateRecordingFile(UploadedFile $file, string $format, int $duration, $user): void
    {
        // Check file size limits based on user tier
        $maxSizeMB = $this->getMaxRecordingSize($user);
        $fileSizeMB = $file->getSize() / 1024 / 1024;
        
        if ($fileSizeMB > $maxSizeMB) {
            throw new \Exception("File size ({$fileSizeMB}MB) exceeds limit ({$maxSizeMB}MB) for your subscription tier");
        }

        // Validate file format
        $allowedFormats = ['mp4', 'mp3', 'wav', 'webm', 'm4a'];
        if (!in_array(strtolower($format), $allowedFormats)) {
            throw new \Exception("Unsupported file format: {$format}");
        }

        // Validate duration
        if ($duration < 1 || $duration > 28800) { // Max 8 hours
            throw new \Exception('Recording duration must be between 1 and 28800 seconds');
        }

        // Validate file mime type
        $mimeType = $file->getMimeType();
        $allowedMimeTypes = [
            'video/mp4',
            'audio/mp3',
            'audio/mpeg',
            'audio/wav',
            'video/webm',
            'audio/mp4',
        ];
        
        if (!in_array($mimeType, $allowedMimeTypes)) {
            throw new \Exception("Invalid file type: {$mimeType}");
        }
    }

    /**
     * Get maximum recording size based on user tier
     */
    protected function getMaxRecordingSize($user): int
    {
        $tier = $user->tenant->subscription_tier ?? 'free';
        
        $limits = [
            'free' => 50,      // 50MB
            'starter' => 200,   // 200MB
            'professional' => 500, // 500MB
            'enterprise' => 2000,  // 2GB
        ];

        return $limits[$tier] ?? $limits['free'];
    }

    /**
     * Store recording file securely
     */
    protected function storeRecordingFile(UploadedFile $file, Meeting $meeting, $user): string
    {
        $timestamp = now()->format('Y-m-d-H-i-s');
        $filename = "{$meeting->id}_{$timestamp}_{$file->getClientOriginalName()}";
        
        // Store in tenant-specific directory
        $path = "recordings/tenant_{$meeting->tenant_id}/{$filename}";
        
        return Storage::disk('private')->putFileAs(
            dirname($path),
            $file,
            basename($path)
        );
    }

    /**
     * Queue recording processing job
     */
    protected function queueRecordingProcessing(Recording $recording, Meeting $meeting): void
    {
        // This would queue a job to process the recording
        // For now, we'll just log the action
        $this->monitoringService->recordMetric('recording.processing.queued', 1, [
            'recording_id' => $recording->id,
            'meeting_id' => $meeting->id,
        ]);
    }

    /**
     * Generate AI insights from meeting data
     */
    protected function generateMeetingInsights(Meeting $meeting): array
    {
        // This would integrate with AI services to analyze transcripts
        // For now, return mock insights
        return [
            [
                'type' => 'key_topic',
                'title' => 'Project Timeline Discussion',
                'content' => 'The team discussed project milestones and delivery dates.',
                'confidence_score' => 0.89,
                'metadata' => ['frequency' => 12],
                'tags' => ['timeline', 'project', 'delivery'],
            ],
            [
                'type' => 'action_item',
                'title' => 'Follow up on budget approval',
                'content' => 'John needs to follow up with finance team for budget approval by Friday.',
                'confidence_score' => 0.95,
                'metadata' => ['assignee' => 'John', 'due_date' => 'Friday'],
                'tags' => ['action', 'budget', 'finance'],
            ],
        ];
    }

    /**
     * Validate action item input
     */
    protected function validateActionItemInput(array $input): void
    {
        if (empty(trim($input['title']))) {
            throw new \Exception('Action item title cannot be empty');
        }

        if (strlen($input['title']) > 255) {
            throw new \Exception('Action item title cannot exceed 255 characters');
        }

        if (isset($input['assignee_email']) && !filter_var($input['assignee_email'], FILTER_VALIDATE_EMAIL)) {
            throw new \Exception('Invalid assignee email address');
        }

        if (isset($input['due_date'])) {
            $dueDate = Carbon::parse($input['due_date']);
            if ($dueDate->isPast()) {
                throw new \Exception('Due date cannot be in the past');
            }
        }

        $validPriorities = ['low', 'medium', 'high', 'critical'];
        if (!in_array($input['priority'], $validPriorities)) {
            throw new \Exception('Invalid priority level');
        }
    }

    /**
     * Create vector embedding for action item
     */
    protected function createActionItemEmbedding(ActionItem $actionItem, Meeting $meeting): void
    {
        $text = $actionItem->title . ' ' . ($actionItem->description ?? '');
        
        $this->vectorService->upsertActionItem([
            'id' => $actionItem->id,
            'text' => $text,
            'metadata' => [
                'action_item_id' => $actionItem->id,
                'meeting_id' => $meeting->id,
                'tenant_id' => $meeting->tenant_id,
                'priority' => $actionItem->priority,
                'status' => $actionItem->status,
                'created_at' => $actionItem->created_at->toISOString(),
            ],
        ]);
    }

    /**
     * Update vector embedding for action item
     */
    protected function updateActionItemEmbedding(ActionItem $actionItem): void
    {
        $this->createActionItemEmbedding($actionItem, $actionItem->meeting);
    }

    /**
     * Remove vector embedding for action item
     */
    protected function removeActionItemEmbedding(ActionItem $actionItem): void
    {
        $this->vectorService->deleteActionItem($actionItem->id);
    }

    /**
     * Create transcript embeddings for search
     */
    protected function createTranscriptEmbeddings(Meeting $meeting): void
    {
        foreach ($meeting->transcripts as $transcript) {
            $this->vectorService->upsertTranscript([
                'id' => $transcript->id,
                'text' => $transcript->content,
                'metadata' => [
                    'transcript_id' => $transcript->id,
                    'meeting_id' => $meeting->id,
                    'tenant_id' => $meeting->tenant_id,
                    'language' => $transcript->language,
                    'confidence_score' => $transcript->confidence_score,
                    'created_at' => $transcript->created_at->toISOString(),
                ],
            ]);
        }
    }

    /**
     * Send action item notification
     */
    protected function sendActionItemNotification(ActionItem $actionItem): void
    {
        // This would send email/slack notification to assignee
        $this->monitoringService->recordMetric('action_item.notification.sent', 1, [
            'action_item_id' => $actionItem->id,
            'assignee_email' => $actionItem->assignee_email,
        ]);
    }

    /**
     * Send action item completion notification
     */
    protected function sendActionItemCompletionNotification(ActionItem $actionItem): void
    {
        // This would send completion notification
        $this->monitoringService->recordMetric('action_item.completion_notification.sent', 1, [
            'action_item_id' => $actionItem->id,
        ]);
    }
}