<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class DataSubjectRequest extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'data_subject_id',
        'data_subject_type',
        'request_reference',
        'request_type',
        'status',
        'request_description',
        'requested_data_categories',
        'identity_verification',
        'received_at',
        'due_date',
        'completed_at',
        'rejection_reason',
        'actions_taken',
        'export_file_path',
        'handled_by',
        'metadata'
    ];

    protected $casts = [
        'requested_data_categories' => 'array',
        'identity_verification' => 'array',
        'received_at' => 'datetime',
        'due_date' => 'datetime',
        'completed_at' => 'datetime',
        'actions_taken' => 'array',
        'metadata' => 'array'
    ];

    // Request types (GDPR Articles)
    const TYPE_ACCESS = 'access'; // Article 15
    const TYPE_RECTIFICATION = 'rectification'; // Article 16
    const TYPE_ERASURE = 'erasure'; // Article 17
    const TYPE_RESTRICT_PROCESSING = 'restrict_processing'; // Article 18
    const TYPE_DATA_PORTABILITY = 'data_portability'; // Article 20
    const TYPE_OBJECT_PROCESSING = 'object_processing'; // Article 21
    const TYPE_WITHDRAW_CONSENT = 'withdraw_consent';

    // Request statuses
    const STATUS_PENDING = 'pending';
    const STATUS_UNDER_REVIEW = 'under_review';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_REJECTED = 'rejected';
    const STATUS_PARTIALLY_COMPLETED = 'partially_completed';

    /**
     * Relationships
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function dataSubject(): BelongsTo
    {
        if ($this->data_subject_type === 'tenant_user') {
            return $this->belongsTo(TenantUser::class, 'data_subject_id');
        }
        
        return $this->belongsTo(TenantUser::class, 'data_subject_id');
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'handled_by');
    }

    /**
     * Scopes
     */
    public function scopeByTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeByDataSubject($query, $dataSubjectId, $type = 'tenant_user')
    {
        return $query->where('data_subject_id', $dataSubjectId)
                    ->where('data_subject_type', $type);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('request_type', $type);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', Carbon::now())
                    ->whereNotIn('status', [self::STATUS_COMPLETED, self::STATUS_REJECTED]);
    }

    public function scopeDueSoon($query, $days = 7)
    {
        return $query->where('due_date', '<=', Carbon::now()->addDays($days))
                    ->where('due_date', '>', Carbon::now())
                    ->whereNotIn('status', [self::STATUS_COMPLETED, self::STATUS_REJECTED]);
    }

    /**
     * Boot method to set defaults
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($request) {
            if (empty($request->request_reference)) {
                $request->request_reference = $request->generateReference();
            }
            
            if (empty($request->received_at)) {
                $request->received_at = Carbon::now();
            }
            
            if (empty($request->due_date)) {
                $request->due_date = Carbon::now()->addDays(30); // GDPR requires response within 30 days
            }
        });
    }

    /**
     * Generate a human-readable reference
     */
    public function generateReference(): string
    {
        $prefix = strtoupper(substr($this->request_type, 0, 3));
        $timestamp = Carbon::now()->format('Ymd');
        $random = strtoupper(substr(md5(uniqid()), 0, 4));
        
        return "{$prefix}-{$timestamp}-{$random}";
    }

    /**
     * Check if request is overdue
     */
    public function isOverdue(): bool
    {
        return $this->due_date->isPast() && 
               !in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_REJECTED]);
    }

    /**
     * Get days remaining until due date
     */
    public function getDaysRemaining(): int
    {
        if ($this->isCompleted()) {
            return 0;
        }

        return max(0, Carbon::now()->diffInDays($this->due_date, false));
    }

    /**
     * Check if request is completed
     */
    public function isCompleted(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_PARTIALLY_COMPLETED]);
    }

    /**
     * Update request status
     */
    public function updateStatus(string $status, string $reason = null, array $actionsTaken = []): self
    {
        $oldStatus = $this->status;
        
        $updateData = ['status' => $status];
        
        if ($status === self::STATUS_COMPLETED || $status === self::STATUS_PARTIALLY_COMPLETED) {
            $updateData['completed_at'] = Carbon::now();
        }
        
        if ($status === self::STATUS_REJECTED && $reason) {
            $updateData['rejection_reason'] = $reason;
        }
        
        if (!empty($actionsTaken)) {
            $updateData['actions_taken'] = array_merge($this->actions_taken ?? [], $actionsTaken);
        }
        
        $this->update($updateData);

        // Log the status change
        ComplianceAuditLog::logEvent(
            'dsr_status_changed',
            'gdpr',
            'info',
            $this->data_subject_id,
            $this->data_subject_type,
            "Data subject request {$this->request_reference} status changed from {$oldStatus} to {$status}",
            [
                'request_id' => $this->id,
                'request_type' => $this->request_type,
                'old_status' => $oldStatus,
                'new_status' => $status,
                'reason' => $reason,
                'actions_taken' => $actionsTaken
            ],
            $this->tenant_id
        );

        return $this;
    }

    /**
     * Process access request (Article 15)
     */
    public function processAccessRequest(): array
    {
        if ($this->request_type !== self::TYPE_ACCESS) {
            throw new \InvalidArgumentException('Not an access request');
        }

        $this->updateStatus(self::STATUS_IN_PROGRESS);

        $userData = $this->collectUserData();
        
        // Generate export file
        $exportPath = $this->generateDataExport($userData);
        $this->update(['export_file_path' => $exportPath]);

        $this->updateStatus(self::STATUS_COMPLETED, null, [
            'data_collected' => count($userData),
            'export_generated' => $exportPath,
            'processed_at' => Carbon::now()->toISOString()
        ]);

        return $userData;
    }

    /**
     * Process erasure request (Article 17 - Right to be forgotten)
     */
    public function processErasureRequest(): array
    {
        if ($this->request_type !== self::TYPE_ERASURE) {
            throw new \InvalidArgumentException('Not an erasure request');
        }

        $this->updateStatus(self::STATUS_IN_PROGRESS);

        $deletedData = $this->deleteUserData();

        $this->updateStatus(self::STATUS_COMPLETED, null, [
            'data_deleted' => $deletedData,
            'processed_at' => Carbon::now()->toISOString()
        ]);

        return $deletedData;
    }

    /**
     * Process data portability request (Article 20)
     */
    public function processDataPortabilityRequest(): string
    {
        if ($this->request_type !== self::TYPE_DATA_PORTABILITY) {
            throw new \InvalidArgumentException('Not a data portability request');
        }

        $this->updateStatus(self::STATUS_IN_PROGRESS);

        $userData = $this->collectPortableData();
        $exportPath = $this->generatePortableExport($userData);
        
        $this->update(['export_file_path' => $exportPath]);
        $this->updateStatus(self::STATUS_COMPLETED, null, [
            'export_generated' => $exportPath,
            'processed_at' => Carbon::now()->toISOString()
        ]);

        return $exportPath;
    }

    /**
     * Collect all user data across the system
     */
    private function collectUserData(): array
    {
        $userData = [];

        // Collect from main user data
        if ($this->data_subject_type === 'tenant_user') {
            $user = TenantUser::find($this->data_subject_id);
            if ($user) {
                $userData['profile'] = $user->toArray();
            }
        }

        // Collect orchestration runs
        $userData['orchestration_runs'] = OrchestrationRun::where('tenant_id', $this->tenant_id)
            ->whereJsonContains('metadata->user_id', $this->data_subject_id)
            ->get()
            ->toArray();

        // Collect agent executions
        $userData['agent_executions'] = AgentExecution::where('tenant_id', $this->tenant_id)
            ->whereJsonContains('metadata->user_id', $this->data_subject_id)
            ->get()
            ->toArray();

        // Collect memory items
        $userData['memory_items'] = MemoryItem::where('tenant_id', $this->tenant_id)
            ->whereJsonContains('metadata->user_id', $this->data_subject_id)
            ->get()
            ->toArray();

        // Collect consent records
        $userData['consent_records'] = ConsentRecord::byDataSubject($this->data_subject_id, $this->data_subject_type)
            ->where('tenant_id', $this->tenant_id)
            ->get()
            ->toArray();

        // Collect security audit logs
        $userData['security_logs'] = SecurityAuditLog::where('tenant_id', $this->tenant_id)
            ->whereJsonContains('metadata->user_id', $this->data_subject_id)
            ->get()
            ->toArray();

        return $userData;
    }

    /**
     * Collect data in portable format (structured for export)
     */
    private function collectPortableData(): array
    {
        $data = $this->collectUserData();
        
        // Structure data for portability (JSON format)
        return [
            'export_info' => [
                'generated_at' => Carbon::now()->toISOString(),
                'request_reference' => $this->request_reference,
                'data_subject_id' => $this->data_subject_id,
                'format' => 'JSON'
            ],
            'personal_data' => $data
        ];
    }

    /**
     * Delete user data across the system
     */
    private function deleteUserData(): array
    {
        $deleted = [];

        // Delete or anonymize orchestration runs
        $runs = OrchestrationRun::where('tenant_id', $this->tenant_id)
            ->whereJsonContains('metadata->user_id', $this->data_subject_id);
        $deleted['orchestration_runs'] = $runs->count();
        $runs->delete();

        // Delete agent executions
        $executions = AgentExecution::where('tenant_id', $this->tenant_id)
            ->whereJsonContains('metadata->user_id', $this->data_subject_id);
        $deleted['agent_executions'] = $executions->count();
        $executions->delete();

        // Delete memory items
        $memories = MemoryItem::where('tenant_id', $this->tenant_id)
            ->whereJsonContains('metadata->user_id', $this->data_subject_id);
        $deleted['memory_items'] = $memories->count();
        $memories->delete();

        // Mark consent records as withdrawn
        $consents = ConsentRecord::byDataSubject($this->data_subject_id, $this->data_subject_type)
            ->where('tenant_id', $this->tenant_id)
            ->active();
        $deleted['consent_records'] = $consents->count();
        $consents->each(fn($consent) => $consent->withdraw('Data erasure request'));

        // Anonymize security logs (don't delete for audit purposes)
        $securityLogs = SecurityAuditLog::where('tenant_id', $this->tenant_id)
            ->whereJsonContains('metadata->user_id', $this->data_subject_id);
        $deleted['security_logs_anonymized'] = $securityLogs->count();
        $securityLogs->update(['user_id' => null, 'ip_address' => '0.0.0.0']);

        // Delete user profile if tenant user
        if ($this->data_subject_type === 'tenant_user') {
            $user = TenantUser::find($this->data_subject_id);
            if ($user) {
                $user->delete();
                $deleted['user_profile'] = 1;
            }
        }

        return $deleted;
    }

    /**
     * Generate data export file
     */
    private function generateDataExport(array $data): string
    {
        $filename = "data_export_{$this->request_reference}_" . Carbon::now()->format('Y-m-d_H-i-s') . '.json';
        $path = storage_path("app/exports/{$filename}");
        
        // Ensure directory exists
        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
        
        return $path;
    }

    /**
     * Generate portable data export
     */
    private function generatePortableExport(array $data): string
    {
        $filename = "portable_data_{$this->request_reference}_" . Carbon::now()->format('Y-m-d_H-i-s') . '.json';
        $path = storage_path("app/exports/{$filename}");
        
        // Ensure directory exists
        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
        
        return $path;
    }

    /**
     * Get all request types with descriptions
     */
    public static function getRequestTypes(): array
    {
        return [
            self::TYPE_ACCESS => 'Access to personal data (Article 15)',
            self::TYPE_RECTIFICATION => 'Rectification of personal data (Article 16)',
            self::TYPE_ERASURE => 'Erasure of personal data (Article 17)',
            self::TYPE_RESTRICT_PROCESSING => 'Restriction of processing (Article 18)',
            self::TYPE_DATA_PORTABILITY => 'Data portability (Article 20)',
            self::TYPE_OBJECT_PROCESSING => 'Object to processing (Article 21)',
            self::TYPE_WITHDRAW_CONSENT => 'Withdraw consent'
        ];
    }

    /**
     * Get all request statuses
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_PENDING => 'Pending review',
            self::STATUS_UNDER_REVIEW => 'Under review',
            self::STATUS_IN_PROGRESS => 'In progress',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_PARTIALLY_COMPLETED => 'Partially completed'
        ];
    }
}