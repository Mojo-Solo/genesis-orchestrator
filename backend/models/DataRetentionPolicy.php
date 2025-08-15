<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class DataRetentionPolicy extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'policy_name',
        'policy_description',
        'is_active',
        'data_category',
        'legal_basis',
        'retention_period_days',
        'retention_action',
        'conditions',
        'exceptions',
        'auto_execute',
        'notification_emails',
        'warning_days',
        'effective_from',
        'effective_until',
        'created_by',
        'approved_by',
        'approved_at'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'conditions' => 'array',
        'exceptions' => 'array',
        'auto_execute' => 'boolean',
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
        'approved_at' => 'datetime'
    ];

    // Legal basis for data retention
    const BASIS_CONSENT = 'consent';
    const BASIS_CONTRACT = 'contract';
    const BASIS_LEGAL_OBLIGATION = 'legal_obligation';
    const BASIS_LEGITIMATE_INTEREST = 'legitimate_interest';
    const BASIS_VITAL_INTERESTS = 'vital_interests';
    const BASIS_PUBLIC_TASK = 'public_task';

    // Retention actions
    const ACTION_DELETE = 'delete';
    const ACTION_ANONYMIZE = 'anonymize';
    const ACTION_ARCHIVE = 'archive';
    const ACTION_NOTIFY_REVIEW = 'notify_review';

    // Data categories
    const CATEGORY_USER_DATA = 'user_data';
    const CATEGORY_TRANSACTION_DATA = 'transaction_data';
    const CATEGORY_COMMUNICATION_DATA = 'communication_data';
    const CATEGORY_BEHAVIORAL_DATA = 'behavioral_data';
    const CATEGORY_TECHNICAL_DATA = 'technical_data';
    const CATEGORY_MARKETING_DATA = 'marketing_data';

    /**
     * Relationships
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'approved_by');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(DataRetentionExecution::class, 'policy_id');
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeEffective($query, $date = null)
    {
        $date = $date ?: Carbon::now();
        
        return $query->where('effective_from', '<=', $date)
                    ->where(function($q) use ($date) {
                        $q->whereNull('effective_until')
                          ->orWhere('effective_until', '>=', $date);
                    });
    }

    public function scopeAutoExecute($query)
    {
        return $query->where('auto_execute', true);
    }

    public function scopeByDataCategory($query, $category)
    {
        return $query->where('data_category', $category);
    }

    public function scopeByLegalBasis($query, $basis)
    {
        return $query->where('legal_basis', $basis);
    }

    public function scopeApproved($query)
    {
        return $query->whereNotNull('approved_by')
                    ->whereNotNull('approved_at');
    }

    public function scopePendingApproval($query)
    {
        return $query->whereNull('approved_by')
                    ->orWhereNull('approved_at');
    }

    /**
     * Check if policy is currently effective
     */
    public function isEffective(): bool
    {
        $now = Carbon::now();
        
        if ($this->effective_from->isFuture()) {
            return false;
        }
        
        if ($this->effective_until && $this->effective_until->isPast()) {
            return false;
        }
        
        return $this->is_active && $this->isApproved();
    }

    /**
     * Check if policy is approved
     */
    public function isApproved(): bool
    {
        return !is_null($this->approved_by) && !is_null($this->approved_at);
    }

    /**
     * Approve the policy
     */
    public function approve($approvedBy): self
    {
        $this->update([
            'approved_by' => $approvedBy,
            'approved_at' => Carbon::now()
        ]);

        // Log the approval
        ComplianceAuditLog::logEvent(
            'retention_policy_approved',
            'gdpr',
            'info',
            null,
            null,
            "Data retention policy '{$this->policy_name}' approved",
            [
                'policy_id' => $this->id,
                'policy_name' => $this->policy_name,
                'approved_by' => $approvedBy,
                'retention_period_days' => $this->retention_period_days,
                'retention_action' => $this->retention_action
            ],
            $this->tenant_id
        );

        return $this;
    }

    /**
     * Get data age threshold
     */
    public function getAgeThreshold(): Carbon
    {
        return Carbon::now()->subDays($this->retention_period_days);
    }

    /**
     * Check if data matches policy conditions
     */
    public function matchesConditions(array $dataAttributes): bool
    {
        if (empty($this->conditions)) {
            return true; // No conditions means applies to all data in category
        }

        foreach ($this->conditions as $condition) {
            $field = $condition['field'] ?? null;
            $operator = $condition['operator'] ?? 'equals';
            $value = $condition['value'] ?? null;

            if (!$field || !isset($dataAttributes[$field])) {
                continue;
            }

            $dataValue = $dataAttributes[$field];

            $matches = match($operator) {
                'equals' => $dataValue == $value,
                'not_equals' => $dataValue != $value,
                'contains' => str_contains($dataValue, $value),
                'not_contains' => !str_contains($dataValue, $value),
                'greater_than' => $dataValue > $value,
                'less_than' => $dataValue < $value,
                'in' => in_array($dataValue, (array)$value),
                'not_in' => !in_array($dataValue, (array)$value),
                default => false
            };

            if (!$matches) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if data has exceptions
     */
    public function hasExceptions(array $dataAttributes): bool
    {
        if (empty($this->exceptions)) {
            return false;
        }

        foreach ($this->exceptions as $exception) {
            $reason = $exception['reason'] ?? null;
            $conditions = $exception['conditions'] ?? [];

            if ($reason === 'legal_hold') {
                return true; // Always exempt if under legal hold
            }

            // Check exception conditions
            $matchesException = true;
            foreach ($conditions as $condition) {
                $field = $condition['field'] ?? null;
                $operator = $condition['operator'] ?? 'equals';
                $value = $condition['value'] ?? null;

                if (!$field || !isset($dataAttributes[$field])) {
                    continue;
                }

                $dataValue = $dataAttributes[$field];
                $matches = match($operator) {
                    'equals' => $dataValue == $value,
                    'not_equals' => $dataValue != $value,
                    'contains' => str_contains($dataValue, $value),
                    'greater_than' => $dataValue > $value,
                    'less_than' => $dataValue < $value,
                    default => false
                };

                if (!$matches) {
                    $matchesException = false;
                    break;
                }
            }

            if ($matchesException) {
                return true;
            }
        }

        return false;
    }

    /**
     * Execute the retention policy
     */
    public function execute($executedBy = null): DataRetentionExecution
    {
        $execution = DataRetentionExecution::create([
            'tenant_id' => $this->tenant_id,
            'policy_id' => $this->id,
            'execution_type' => $executedBy ? 'manual' : 'scheduled',
            'status' => 'pending',
            'scheduled_at' => Carbon::now(),
            'executed_by' => $executedBy
        ]);

        // Start execution in background
        dispatch(new \App\Jobs\ExecuteRetentionPolicyJob($execution));

        return $execution;
    }

    /**
     * Get data eligibility for retention action
     */
    public function getEligibleData(): array
    {
        $eligibleData = [];
        $ageThreshold = $this->getAgeThreshold();

        switch ($this->data_category) {
            case self::CATEGORY_USER_DATA:
                $eligibleData = $this->getEligibleUserData($ageThreshold);
                break;
            case self::CATEGORY_TRANSACTION_DATA:
                $eligibleData = $this->getEligibleTransactionData($ageThreshold);
                break;
            case self::CATEGORY_COMMUNICATION_DATA:
                $eligibleData = $this->getEligibleCommunicationData($ageThreshold);
                break;
            case self::CATEGORY_BEHAVIORAL_DATA:
                $eligibleData = $this->getEligibleBehavioralData($ageThreshold);
                break;
            case self::CATEGORY_TECHNICAL_DATA:
                $eligibleData = $this->getEligibleTechnicalData($ageThreshold);
                break;
        }

        return $eligibleData;
    }

    /**
     * Get eligible user data
     */
    private function getEligibleUserData(Carbon $ageThreshold): array
    {
        return TenantUser::where('tenant_id', $this->tenant_id)
            ->where('created_at', '<', $ageThreshold)
            ->whereNull('deleted_at') // Only active users
            ->get()
            ->filter(function($user) {
                $attributes = $user->toArray();
                return $this->matchesConditions($attributes) && !$this->hasExceptions($attributes);
            })
            ->map(function($user) {
                return [
                    'table' => 'tenant_users',
                    'id' => $user->id,
                    'age_days' => $user->created_at->diffInDays(Carbon::now()),
                    'attributes' => $user->toArray()
                ];
            })
            ->toArray();
    }

    /**
     * Get eligible transaction data
     */
    private function getEligibleTransactionData(Carbon $ageThreshold): array
    {
        return OrchestrationRun::where('tenant_id', $this->tenant_id)
            ->where('created_at', '<', $ageThreshold)
            ->get()
            ->filter(function($run) {
                $attributes = $run->toArray();
                return $this->matchesConditions($attributes) && !$this->hasExceptions($attributes);
            })
            ->map(function($run) {
                return [
                    'table' => 'orchestration_runs',
                    'id' => $run->id,
                    'age_days' => $run->created_at->diffInDays(Carbon::now()),
                    'attributes' => $run->toArray()
                ];
            })
            ->toArray();
    }

    /**
     * Get eligible communication data
     */
    private function getEligibleCommunicationData(Carbon $ageThreshold): array
    {
        // This would include things like notifications, emails, etc.
        // For now, we'll use security audit logs as an example
        return SecurityAuditLog::where('tenant_id', $this->tenant_id)
            ->where('created_at', '<', $ageThreshold)
            ->get()
            ->filter(function($log) {
                $attributes = $log->toArray();
                return $this->matchesConditions($attributes) && !$this->hasExceptions($attributes);
            })
            ->map(function($log) {
                return [
                    'table' => 'security_audit_logs',
                    'id' => $log->id,
                    'age_days' => $log->created_at->diffInDays(Carbon::now()),
                    'attributes' => $log->toArray()
                ];
            })
            ->toArray();
    }

    /**
     * Get eligible behavioral data
     */
    private function getEligibleBehavioralData(Carbon $ageThreshold): array
    {
        return RouterMetric::where('tenant_id', $this->tenant_id)
            ->where('created_at', '<', $ageThreshold)
            ->get()
            ->filter(function($metric) {
                $attributes = $metric->toArray();
                return $this->matchesConditions($attributes) && !$this->hasExceptions($attributes);
            })
            ->map(function($metric) {
                return [
                    'table' => 'router_metrics',
                    'id' => $metric->id,
                    'age_days' => $metric->created_at->diffInDays(Carbon::now()),
                    'attributes' => $metric->toArray()
                ];
            })
            ->toArray();
    }

    /**
     * Get eligible technical data
     */
    private function getEligibleTechnicalData(Carbon $ageThreshold): array
    {
        return StabilityTracking::where('tenant_id', $this->tenant_id)
            ->where('created_at', '<', $ageThreshold)
            ->get()
            ->filter(function($tracking) {
                $attributes = $tracking->toArray();
                return $this->matchesConditions($attributes) && !$this->hasExceptions($attributes);
            })
            ->map(function($tracking) {
                return [
                    'table' => 'stability_tracking',
                    'id' => $tracking->id,
                    'age_days' => $tracking->created_at->diffInDays(Carbon::now()),
                    'attributes' => $tracking->toArray()
                ];
            })
            ->toArray();
    }

    /**
     * Get all legal basis options
     */
    public static function getLegalBasisOptions(): array
    {
        return [
            self::BASIS_CONSENT => 'Consent - data subject has given consent',
            self::BASIS_CONTRACT => 'Contract - processing is necessary for a contract',
            self::BASIS_LEGAL_OBLIGATION => 'Legal obligation - required by law',
            self::BASIS_LEGITIMATE_INTEREST => 'Legitimate interests - necessary for legitimate interests',
            self::BASIS_VITAL_INTERESTS => 'Vital interests - necessary to protect vital interests',
            self::BASIS_PUBLIC_TASK => 'Public task - necessary for public interest'
        ];
    }

    /**
     * Get all retention action options
     */
    public static function getRetentionActionOptions(): array
    {
        return [
            self::ACTION_DELETE => 'Delete - permanently remove data',
            self::ACTION_ANONYMIZE => 'Anonymize - remove personal identifiers',
            self::ACTION_ARCHIVE => 'Archive - move to long-term storage',
            self::ACTION_NOTIFY_REVIEW => 'Notify for manual review'
        ];
    }

    /**
     * Get all data category options
     */
    public static function getDataCategoryOptions(): array
    {
        return [
            self::CATEGORY_USER_DATA => 'User Data - personal information',
            self::CATEGORY_TRANSACTION_DATA => 'Transaction Data - business transactions',
            self::CATEGORY_COMMUNICATION_DATA => 'Communication Data - messages and notifications',
            self::CATEGORY_BEHAVIORAL_DATA => 'Behavioral Data - usage patterns and analytics',
            self::CATEGORY_TECHNICAL_DATA => 'Technical Data - system logs and metrics',
            self::CATEGORY_MARKETING_DATA => 'Marketing Data - promotional communications'
        ];
    }
}