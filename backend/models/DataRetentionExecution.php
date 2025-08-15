<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class DataRetentionExecution extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'policy_id',
        'execution_type',
        'status',
        'scheduled_at',
        'started_at',
        'completed_at',
        'records_identified',
        'records_processed',
        'records_deleted',
        'records_anonymized',
        'records_archived',
        'records_failed',
        'affected_tables',
        'execution_log',
        'error_details',
        'executed_by'
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'affected_tables' => 'array'
    ];

    // Execution types
    const TYPE_SCHEDULED = 'scheduled';
    const TYPE_MANUAL = 'manual';
    const TYPE_TRIGGERED = 'triggered';

    // Execution statuses
    const STATUS_PENDING = 'pending';
    const STATUS_RUNNING = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Relationships
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(DataRetentionPolicy::class, 'policy_id');
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'executed_by');
    }

    /**
     * Scopes
     */
    public function scopeByTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeByPolicy($query, $policyId)
    {
        return $query->where('policy_id', $policyId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeRunning($query)
    {
        return $query->where('status', self::STATUS_RUNNING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeRecent($query, $days = 30)
    {
        return $query->where('scheduled_at', '>=', Carbon::now()->subDays($days));
    }

    /**
     * Start the execution
     */
    public function start(): self
    {
        $this->update([
            'status' => self::STATUS_RUNNING,
            'started_at' => Carbon::now()
        ]);

        $this->log("Execution started");

        // Log the start
        ComplianceAuditLog::logEvent(
            'retention_execution_started',
            'gdpr',
            'info',
            null,
            null,
            "Data retention execution started for policy '{$this->policy->policy_name}'",
            [
                'execution_id' => $this->id,
                'policy_id' => $this->policy_id,
                'execution_type' => $this->execution_type,
                'started_at' => $this->started_at->toISOString()
            ],
            $this->tenant_id
        );

        return $this;
    }

    /**
     * Complete the execution successfully
     */
    public function complete(): self
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => Carbon::now()
        ]);

        $this->log("Execution completed successfully");

        // Log the completion
        ComplianceAuditLog::logEvent(
            'retention_execution_completed',
            'gdpr',
            'info',
            null,
            null,
            "Data retention execution completed for policy '{$this->policy->policy_name}'",
            [
                'execution_id' => $this->id,
                'policy_id' => $this->policy_id,
                'records_processed' => $this->records_processed,
                'records_deleted' => $this->records_deleted,
                'records_anonymized' => $this->records_anonymized,
                'records_archived' => $this->records_archived,
                'duration_seconds' => $this->getDurationInSeconds()
            ],
            $this->tenant_id
        );

        return $this;
    }

    /**
     * Mark execution as failed
     */
    public function fail(string $error): self
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'completed_at' => Carbon::now(),
            'error_details' => $error
        ]);

        $this->log("Execution failed: {$error}");

        // Log the failure
        ComplianceAuditLog::logEvent(
            'retention_execution_failed',
            'gdpr',
            'error',
            null,
            null,
            "Data retention execution failed for policy '{$this->policy->policy_name}'",
            [
                'execution_id' => $this->id,
                'policy_id' => $this->policy_id,
                'error_details' => $error,
                'records_processed' => $this->records_processed
            ],
            $this->tenant_id
        );

        return $this;
    }

    /**
     * Cancel the execution
     */
    public function cancel(string $reason = null): self
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'completed_at' => Carbon::now(),
            'error_details' => $reason
        ]);

        $this->log("Execution cancelled: {$reason}");

        return $this;
    }

    /**
     * Add log entry
     */
    public function log(string $message): self
    {
        $timestamp = Carbon::now()->toISOString();
        $logEntry = "[{$timestamp}] {$message}\n";
        
        $this->execution_log = ($this->execution_log ?? '') . $logEntry;
        $this->save();

        return $this;
    }

    /**
     * Update record counts
     */
    public function updateCounts(array $counts): self
    {
        $updateData = [];
        
        foreach ($counts as $key => $value) {
            if (in_array($key, [
                'records_identified',
                'records_processed', 
                'records_deleted',
                'records_anonymized',
                'records_archived',
                'records_failed'
            ])) {
                $updateData[$key] = $value;
            }
        }

        if (!empty($updateData)) {
            $this->update($updateData);
        }

        return $this;
    }

    /**
     * Add affected table
     */
    public function addAffectedTable(string $table, int $recordCount): self
    {
        $affectedTables = $this->affected_tables ?? [];
        $affectedTables[$table] = $recordCount;
        
        $this->update(['affected_tables' => $affectedTables]);

        return $this;
    }

    /**
     * Get execution duration in seconds
     */
    public function getDurationInSeconds(): int
    {
        if (!$this->started_at) {
            return 0;
        }

        $endTime = $this->completed_at ?: Carbon::now();
        return $this->started_at->diffInSeconds($endTime);
    }

    /**
     * Get execution duration in human readable format
     */
    public function getDurationForHumans(): string
    {
        if (!$this->started_at) {
            return 'Not started';
        }

        $endTime = $this->completed_at ?: Carbon::now();
        return $this->started_at->diffForHumans($endTime, true);
    }

    /**
     * Check if execution is in progress
     */
    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    /**
     * Check if execution is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if execution failed
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Get success rate
     */
    public function getSuccessRate(): float
    {
        if ($this->records_identified === 0) {
            return 100.0;
        }

        $successful = $this->records_deleted + $this->records_anonymized + $this->records_archived;
        return ($successful / $this->records_identified) * 100;
    }

    /**
     * Get execution summary
     */
    public function getSummary(): array
    {
        return [
            'execution_id' => $this->id,
            'policy_name' => $this->policy->policy_name,
            'status' => $this->status,
            'execution_type' => $this->execution_type,
            'scheduled_at' => $this->scheduled_at,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'duration_seconds' => $this->getDurationInSeconds(),
            'duration_human' => $this->getDurationForHumans(),
            'records' => [
                'identified' => $this->records_identified,
                'processed' => $this->records_processed,
                'deleted' => $this->records_deleted,
                'anonymized' => $this->records_anonymized,
                'archived' => $this->records_archived,
                'failed' => $this->records_failed
            ],
            'success_rate' => $this->getSuccessRate(),
            'affected_tables' => $this->affected_tables,
            'error_details' => $this->error_details
        ];
    }

    /**
     * Get execution statistics for a tenant
     */
    public static function getStatistics($tenantId, $days = 30): array
    {
        $executions = self::byTenant($tenantId)
            ->where('scheduled_at', '>=', Carbon::now()->subDays($days))
            ->get();

        return [
            'total_executions' => $executions->count(),
            'completed_executions' => $executions->where('status', self::STATUS_COMPLETED)->count(),
            'failed_executions' => $executions->where('status', self::STATUS_FAILED)->count(),
            'running_executions' => $executions->where('status', self::STATUS_RUNNING)->count(),
            'total_records_processed' => $executions->sum('records_processed'),
            'total_records_deleted' => $executions->sum('records_deleted'),
            'total_records_anonymized' => $executions->sum('records_anonymized'),
            'total_records_archived' => $executions->sum('records_archived'),
            'average_duration_seconds' => $executions->where('status', self::STATUS_COMPLETED)
                ->map(fn($e) => $e->getDurationInSeconds())
                ->average(),
            'success_rate' => $executions->isEmpty() ? 100 : 
                ($executions->where('status', self::STATUS_COMPLETED)->count() / $executions->count()) * 100
        ];
    }

    /**
     * Get all execution types
     */
    public static function getExecutionTypes(): array
    {
        return [
            self::TYPE_SCHEDULED => 'Scheduled execution',
            self::TYPE_MANUAL => 'Manual execution', 
            self::TYPE_TRIGGERED => 'Event-triggered execution'
        ];
    }

    /**
     * Get all execution statuses
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_RUNNING => 'Running',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELLED => 'Cancelled'
        ];
    }
}