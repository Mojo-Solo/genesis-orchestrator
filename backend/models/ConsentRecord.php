<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class ConsentRecord extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'data_subject_id',
        'data_subject_type',
        'consent_type',
        'processing_purpose',
        'consent_description',
        'consent_status',
        'consent_given_at',
        'consent_withdrawn_at',
        'consent_expires_at',
        'consent_method',
        'consent_source',
        'consent_evidence',
        'is_active',
        'is_granular',
        'granular_permissions',
        'superseded_by'
    ];

    protected $casts = [
        'consent_given_at' => 'datetime',
        'consent_withdrawn_at' => 'datetime',
        'consent_expires_at' => 'datetime',
        'consent_evidence' => 'array',
        'is_active' => 'boolean',
        'is_granular' => 'boolean',
        'granular_permissions' => 'array'
    ];

    // Consent statuses
    const STATUS_GRANTED = 'granted';
    const STATUS_WITHDRAWN = 'withdrawn';
    const STATUS_EXPIRED = 'expired';
    const STATUS_PENDING = 'pending';

    // Consent types
    const TYPE_DATA_PROCESSING = 'data_processing';
    const TYPE_MARKETING = 'marketing';
    const TYPE_ANALYTICS = 'analytics';
    const TYPE_COOKIES = 'cookies';
    const TYPE_PROFILING = 'profiling';
    const TYPE_AUTOMATED_DECISION = 'automated_decision';

    // Processing purposes
    const PURPOSE_SERVICE_PROVISION = 'service_provision';
    const PURPOSE_ANALYTICS = 'analytics';
    const PURPOSE_MARKETING = 'marketing';
    const PURPOSE_LEGAL_COMPLIANCE = 'legal_compliance';
    const PURPOSE_LEGITIMATE_INTEREST = 'legitimate_interest';
    const PURPOSE_RESEARCH = 'research';

    // Consent methods
    const METHOD_EXPLICIT_OPT_IN = 'explicit_opt_in';
    const METHOD_IMPLIED = 'implied';
    const METHOD_LEGITIMATE_INTEREST = 'legitimate_interest';
    const METHOD_PRE_TICKED_BOX = 'pre_ticked_box'; // Not valid under GDPR
    const METHOD_SILENCE = 'silence'; // Not valid under GDPR

    // Consent sources
    const SOURCE_WEB_FORM = 'web_form';
    const SOURCE_API = 'api';
    const SOURCE_PHONE = 'phone';
    const SOURCE_PAPER = 'paper';
    const SOURCE_EMAIL = 'email';
    const SOURCE_SMS = 'sms';

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
        
        // For external users, this would need to be handled differently
        return $this->belongsTo(TenantUser::class, 'data_subject_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(ConsentRecord::class, 'superseded_by');
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                    ->where('consent_status', self::STATUS_GRANTED);
    }

    public function scopeByTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeByDataSubject($query, $dataSubjectId, $type = 'tenant_user')
    {
        return $query->where('data_subject_id', $dataSubjectId)
                    ->where('data_subject_type', $type);
    }

    public function scopeByConsentType($query, $type)
    {
        return $query->where('consent_type', $type);
    }

    public function scopeExpiring($query, $days = 30)
    {
        return $query->where('consent_expires_at', '<=', Carbon::now()->addDays($days))
                    ->where('consent_expires_at', '>', Carbon::now())
                    ->where('consent_status', self::STATUS_GRANTED);
    }

    public function scopeExpired($query)
    {
        return $query->where('consent_expires_at', '<', Carbon::now())
                    ->where('consent_status', '!=', self::STATUS_EXPIRED);
    }

    /**
     * Check if consent is currently valid
     */
    public function isValid(): bool
    {
        if (!$this->is_active || $this->consent_status !== self::STATUS_GRANTED) {
            return false;
        }

        if ($this->consent_expires_at && $this->consent_expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Grant consent
     */
    public function grant(array $evidence = []): self
    {
        $this->update([
            'consent_status' => self::STATUS_GRANTED,
            'consent_given_at' => Carbon::now(),
            'consent_withdrawn_at' => null,
            'consent_evidence' => array_merge($this->consent_evidence ?? [], $evidence),
            'is_active' => true
        ]);

        // Log the consent grant
        ComplianceAuditLog::logEvent(
            'consent_granted',
            'gdpr',
            'info',
            $this->data_subject_id,
            $this->data_subject_type,
            "Consent granted for {$this->consent_type} - {$this->processing_purpose}",
            [
                'consent_id' => $this->id,
                'consent_type' => $this->consent_type,
                'processing_purpose' => $this->processing_purpose,
                'consent_method' => $this->consent_method,
                'evidence' => $evidence
            ],
            $this->tenant_id
        );

        return $this;
    }

    /**
     * Withdraw consent
     */
    public function withdraw(string $reason = null): self
    {
        $this->update([
            'consent_status' => self::STATUS_WITHDRAWN,
            'consent_withdrawn_at' => Carbon::now(),
            'is_active' => false
        ]);

        // Log the consent withdrawal
        ComplianceAuditLog::logEvent(
            'consent_withdrawn',
            'gdpr',
            'warning',
            $this->data_subject_id,
            $this->data_subject_type,
            "Consent withdrawn for {$this->consent_type} - {$this->processing_purpose}",
            [
                'consent_id' => $this->id,
                'consent_type' => $this->consent_type,
                'processing_purpose' => $this->processing_purpose,
                'withdrawal_reason' => $reason
            ],
            $this->tenant_id
        );

        return $this;
    }

    /**
     * Mark consent as expired
     */
    public function markExpired(): self
    {
        $this->update([
            'consent_status' => self::STATUS_EXPIRED,
            'is_active' => false
        ]);

        // Log the consent expiry
        ComplianceAuditLog::logEvent(
            'consent_expired',
            'gdpr',
            'warning',
            $this->data_subject_id,
            $this->data_subject_type,
            "Consent expired for {$this->consent_type} - {$this->processing_purpose}",
            [
                'consent_id' => $this->id,
                'consent_type' => $this->consent_type,
                'processing_purpose' => $this->processing_purpose,
                'expired_at' => $this->consent_expires_at
            ],
            $this->tenant_id
        );

        return $this;
    }

    /**
     * Check if consent method is GDPR compliant
     */
    public function isGdprCompliant(): bool
    {
        // Pre-ticked boxes and silence are not valid consent under GDPR
        $invalidMethods = [self::METHOD_PRE_TICKED_BOX, self::METHOD_SILENCE];
        
        if (in_array($this->consent_method, $invalidMethods)) {
            return false;
        }

        // For special categories, explicit consent is required
        if ($this->isForSpecialCategoryData() && $this->consent_method !== self::METHOD_EXPLICIT_OPT_IN) {
            return false;
        }

        return true;
    }

    /**
     * Check if this consent is for special category data
     */
    public function isForSpecialCategoryData(): bool
    {
        // This would need to check against data classifications
        $specialPurposes = [
            'health_data_processing',
            'biometric_processing',
            'genetic_processing',
            'political_profiling'
        ];

        return in_array($this->processing_purpose, $specialPurposes);
    }

    /**
     * Get consent age in days
     */
    public function getAgeInDays(): int
    {
        if (!$this->consent_given_at) {
            return 0;
        }

        return $this->consent_given_at->diffInDays(Carbon::now());
    }

    /**
     * Get days until expiry
     */
    public function getDaysUntilExpiry(): ?int
    {
        if (!$this->consent_expires_at) {
            return null;
        }

        return Carbon::now()->diffInDays($this->consent_expires_at, false);
    }

    /**
     * Check if specific permission is granted
     */
    public function hasPermission(string $permission): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        if (!$this->is_granular) {
            return true; // All permissions granted
        }

        return in_array($permission, $this->granular_permissions ?? []);
    }

    /**
     * Grant specific permission
     */
    public function grantPermission(string $permission): self
    {
        if (!$this->is_granular) {
            return $this;
        }

        $permissions = $this->granular_permissions ?? [];
        if (!in_array($permission, $permissions)) {
            $permissions[] = $permission;
            $this->update(['granular_permissions' => $permissions]);
        }

        return $this;
    }

    /**
     * Revoke specific permission
     */
    public function revokePermission(string $permission): self
    {
        if (!$this->is_granular) {
            return $this;
        }

        $permissions = $this->granular_permissions ?? [];
        $permissions = array_filter($permissions, fn($p) => $p !== $permission);
        $this->update(['granular_permissions' => array_values($permissions)]);

        return $this;
    }

    /**
     * Create a new consent record
     */
    public static function createConsent(array $data): self
    {
        $consent = self::create($data);

        // Auto-grant if method is explicit opt-in and evidence is provided
        if ($consent->consent_method === self::METHOD_EXPLICIT_OPT_IN && !empty($data['consent_evidence'])) {
            $consent->grant($data['consent_evidence']);
        }

        return $consent;
    }

    /**
     * Get all valid consent types
     */
    public static function getConsentTypes(): array
    {
        return [
            self::TYPE_DATA_PROCESSING => 'General data processing',
            self::TYPE_MARKETING => 'Marketing communications',
            self::TYPE_ANALYTICS => 'Analytics and tracking',
            self::TYPE_COOKIES => 'Cookie usage',
            self::TYPE_PROFILING => 'Profiling and personalization',
            self::TYPE_AUTOMATED_DECISION => 'Automated decision making'
        ];
    }

    /**
     * Get all processing purposes
     */
    public static function getProcessingPurposes(): array
    {
        return [
            self::PURPOSE_SERVICE_PROVISION => 'Service provision',
            self::PURPOSE_ANALYTICS => 'Analytics and insights',
            self::PURPOSE_MARKETING => 'Marketing and promotion',
            self::PURPOSE_LEGAL_COMPLIANCE => 'Legal compliance',
            self::PURPOSE_LEGITIMATE_INTEREST => 'Legitimate business interest',
            self::PURPOSE_RESEARCH => 'Research and development'
        ];
    }
}