<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class PrivacyImpactAssessment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'assessment_name',
        'description',
        'project_or_system',
        'status',
        'risk_level',
        'data_types_processed',
        'processing_purposes',
        'data_sources',
        'data_recipients',
        'transfers_outside_eea',
        'necessity_justification',
        'proportionality_assessment',
        'risks_identified',
        'mitigation_measures',
        'conducted_by',
        'reviewed_by',
        'conducted_at',
        'reviewed_at',
        'next_review_due'
    ];

    protected $casts = [
        'data_types_processed' => 'array',
        'processing_purposes' => 'array',
        'data_sources' => 'array',
        'data_recipients' => 'array',
        'transfers_outside_eea' => 'array',
        'risks_identified' => 'array',
        'mitigation_measures' => 'array',
        'conducted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'next_review_due' => 'datetime'
    ];

    // Assessment statuses
    const STATUS_DRAFT = 'draft';
    const STATUS_UNDER_REVIEW = 'under_review';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_REQUIRES_UPDATE = 'requires_update';

    // Risk levels
    const RISK_LOW = 'low';
    const RISK_MEDIUM = 'medium';
    const RISK_HIGH = 'high';
    const RISK_VERY_HIGH = 'very_high';

    // Data types
    const DATA_TYPE_PERSONAL_IDENTIFIERS = 'personal_identifiers';
    const DATA_TYPE_CONTACT_DETAILS = 'contact_details';
    const DATA_TYPE_FINANCIAL_DATA = 'financial_data';
    const DATA_TYPE_HEALTH_DATA = 'health_data';
    const DATA_TYPE_BIOMETRIC_DATA = 'biometric_data';
    const DATA_TYPE_LOCATION_DATA = 'location_data';
    const DATA_TYPE_BEHAVIORAL_DATA = 'behavioral_data';
    const DATA_TYPE_SPECIAL_CATEGORIES = 'special_categories';

    // Processing purposes
    const PURPOSE_SERVICE_PROVISION = 'service_provision';
    const PURPOSE_ANALYTICS = 'analytics';
    const PURPOSE_MARKETING = 'marketing';
    const PURPOSE_LEGAL_COMPLIANCE = 'legal_compliance';
    const PURPOSE_SECURITY = 'security';
    const PURPOSE_RESEARCH = 'research';

    /**
     * Relationships
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function conductedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'conducted_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'reviewed_by');
    }

    /**
     * Scopes
     */
    public function scopeByTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByRiskLevel($query, $riskLevel)
    {
        return $query->where('risk_level', $riskLevel);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeDueForReview($query, $days = 0)
    {
        return $query->where('next_review_due', '<=', Carbon::now()->addDays($days));
    }

    public function scopeHighRisk($query)
    {
        return $query->whereIn('risk_level', [self::RISK_HIGH, self::RISK_VERY_HIGH]);
    }

    /**
     * Boot method to set defaults
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($assessment) {
            if (empty($assessment->conducted_at)) {
                $assessment->conducted_at = Carbon::now();
            }
            
            if (empty($assessment->next_review_due)) {
                $assessment->next_review_due = Carbon::now()->addYear(); // Annual review by default
            }
        });

        static::updated(function ($assessment) {
            if ($assessment->wasChanged('status') && $assessment->status === self::STATUS_APPROVED) {
                ComplianceAuditLog::logEvent(
                    'pia_approved',
                    'gdpr',
                    'info',
                    null,
                    null,
                    "Privacy Impact Assessment '{$assessment->assessment_name}' approved",
                    [
                        'pia_id' => $assessment->id,
                        'assessment_name' => $assessment->assessment_name,
                        'risk_level' => $assessment->risk_level,
                        'reviewed_by' => $assessment->reviewed_by
                    ],
                    $assessment->tenant_id
                );
            }
        });
    }

    /**
     * Update assessment status
     */
    public function updateStatus(string $status, $reviewedBy = null, string $reason = null): self
    {
        $oldStatus = $this->status;
        
        $updateData = ['status' => $status];
        
        if ($status === self::STATUS_APPROVED || $status === self::STATUS_REJECTED) {
            $updateData['reviewed_at'] = Carbon::now();
            $updateData['reviewed_by'] = $reviewedBy;
        }
        
        $this->update($updateData);

        ComplianceAuditLog::logEvent(
            'pia_status_changed',
            'gdpr',
            'info',
            null,
            null,
            "Privacy Impact Assessment status changed from {$oldStatus} to {$status}",
            [
                'pia_id' => $this->id,
                'assessment_name' => $this->assessment_name,
                'old_status' => $oldStatus,
                'new_status' => $status,
                'reviewed_by' => $reviewedBy,
                'reason' => $reason
            ],
            $this->tenant_id
        );

        return $this;
    }

    /**
     * Calculate risk score based on assessment data
     */
    public function calculateRiskScore(): int
    {
        $score = 0;

        // Base score for data types
        $highRiskDataTypes = [
            self::DATA_TYPE_HEALTH_DATA,
            self::DATA_TYPE_BIOMETRIC_DATA,
            self::DATA_TYPE_SPECIAL_CATEGORIES
        ];

        foreach ($this->data_types_processed ?? [] as $dataType) {
            if (in_array($dataType, $highRiskDataTypes)) {
                $score += 25;
            } else {
                $score += 10;
            }
        }

        // Score for processing purposes
        $sensitiveProcessing = [self::PURPOSE_MARKETING, self::PURPOSE_RESEARCH];
        foreach ($this->processing_purposes ?? [] as $purpose) {
            if (in_array($purpose, $sensitiveProcessing)) {
                $score += 15;
            } else {
                $score += 5;
            }
        }

        // Score for international transfers
        if (!empty($this->transfers_outside_eea)) {
            $score += 20;
        }

        // Score for identified risks
        $riskCount = count($this->risks_identified ?? []);
        $score += $riskCount * 10;

        // Deduct points for mitigation measures
        $mitigationCount = count($this->mitigation_measures ?? []);
        $score = max(0, $score - ($mitigationCount * 5));

        return min($score, 100);
    }

    /**
     * Determine risk level based on score
     */
    public function determineRiskLevel(): string
    {
        $score = $this->calculateRiskScore();

        return match(true) {
            $score >= 80 => self::RISK_VERY_HIGH,
            $score >= 60 => self::RISK_HIGH,
            $score >= 40 => self::RISK_MEDIUM,
            default => self::RISK_LOW
        };
    }

    /**
     * Check if assessment is due for review
     */
    public function isDueForReview(): bool
    {
        return $this->next_review_due && $this->next_review_due->isPast();
    }

    /**
     * Check if assessment requires DPIA (Data Protection Impact Assessment)
     */
    public function requiresDPIA(): bool
    {
        // GDPR Article 35 criteria
        $requiresDPIA = false;

        // Systematic and extensive profiling
        if (in_array(self::PURPOSE_ANALYTICS, $this->processing_purposes ?? []) ||
            in_array(self::DATA_TYPE_BEHAVIORAL_DATA, $this->data_types_processed ?? [])) {
            $requiresDPIA = true;
        }

        // Large scale processing of special categories
        if (in_array(self::DATA_TYPE_SPECIAL_CATEGORIES, $this->data_types_processed ?? []) ||
            in_array(self::DATA_TYPE_HEALTH_DATA, $this->data_types_processed ?? []) ||
            in_array(self::DATA_TYPE_BIOMETRIC_DATA, $this->data_types_processed ?? [])) {
            $requiresDPIA = true;
        }

        // High risk to rights and freedoms
        if ($this->risk_level === self::RISK_HIGH || $this->risk_level === self::RISK_VERY_HIGH) {
            $requiresDPIA = true;
        }

        return $requiresDPIA;
    }

    /**
     * Generate recommendations based on assessment
     */
    public function generateRecommendations(): array
    {
        $recommendations = [];

        // Risk-based recommendations
        if ($this->risk_level === self::RISK_VERY_HIGH) {
            $recommendations[] = [
                'priority' => 'critical',
                'category' => 'risk_mitigation',
                'title' => 'Implement additional security measures',
                'description' => 'Very high risk assessment requires enhanced security controls and regular monitoring.'
            ];
        }

        // Data type specific recommendations
        if (in_array(self::DATA_TYPE_SPECIAL_CATEGORIES, $this->data_types_processed ?? [])) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'legal_compliance',
                'title' => 'Ensure explicit consent for special categories',
                'description' => 'Processing special categories of data requires explicit consent under GDPR Article 9.'
            ];
        }

        // International transfer recommendations
        if (!empty($this->transfers_outside_eea)) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'data_transfers',
                'title' => 'Implement adequate safeguards for international transfers',
                'description' => 'Ensure appropriate safeguards are in place for data transfers outside the EEA.'
            ];
        }

        // Review schedule recommendations
        if ($this->isDueForReview()) {
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'governance',
                'title' => 'Schedule assessment review',
                'description' => 'This assessment is due for review to ensure continued compliance.'
            ];
        }

        return $recommendations;
    }

    /**
     * Create automated PIA based on data classification
     */
    public static function createAutomatedPIA(string $tenantId, string $projectName, array $dataClassifications): self
    {
        $dataTypes = [];
        $riskFactors = [];
        $mitigationMeasures = [];

        foreach ($dataClassifications as $classification) {
            // Map data classifications to PIA data types
            if (!empty($classification->pii_categories)) {
                $dataTypes = array_merge($dataTypes, $classification->pii_categories);
            }

            if (!empty($classification->special_categories)) {
                $dataTypes[] = self::DATA_TYPE_SPECIAL_CATEGORIES;
                $riskFactors[] = "Processing special categories: " . implode(', ', $classification->special_categories);
            }

            if ($classification->requires_encryption) {
                $mitigationMeasures[] = "Data encryption required for " . $classification->table_name;
            }

            if ($classification->cross_border_restricted) {
                $riskFactors[] = "Cross-border transfer restrictions for " . $classification->table_name;
            }
        }

        $assessment = static::create([
            'tenant_id' => $tenantId,
            'assessment_name' => "Automated PIA - {$projectName}",
            'description' => "Automatically generated Privacy Impact Assessment based on data classification analysis.",
            'project_or_system' => $projectName,
            'status' => self::STATUS_DRAFT,
            'data_types_processed' => array_unique($dataTypes),
            'processing_purposes' => [self::PURPOSE_SERVICE_PROVISION],
            'data_sources' => ['system_generated'],
            'data_recipients' => ['internal_systems'],
            'necessity_justification' => 'Processing necessary for service provision and business operations.',
            'proportionality_assessment' => 'Processing is proportionate to the purposes and business requirements.',
            'risks_identified' => $riskFactors,
            'mitigation_measures' => $mitigationMeasures,
            'conducted_by' => null, // System generated
            'conducted_at' => Carbon::now()
        ]);

        // Calculate and set risk level
        $assessment->update(['risk_level' => $assessment->determineRiskLevel()]);

        ComplianceAuditLog::logEvent(
            'automated_pia_created',
            'gdpr',
            'info',
            null,
            null,
            "Automated Privacy Impact Assessment created for {$projectName}",
            [
                'pia_id' => $assessment->id,
                'project_name' => $projectName,
                'risk_level' => $assessment->risk_level,
                'classifications_analyzed' => count($dataClassifications)
            ],
            $tenantId,
            [],
            null,
            true
        );

        return $assessment;
    }

    /**
     * Get PIA template for common scenarios
     */
    public static function getTemplate(string $scenario): array
    {
        $templates = [
            'new_system' => [
                'assessment_name' => 'New System PIA',
                'description' => 'Privacy Impact Assessment for new system implementation',
                'data_types_processed' => [self::DATA_TYPE_PERSONAL_IDENTIFIERS, self::DATA_TYPE_CONTACT_DETAILS],
                'processing_purposes' => [self::PURPOSE_SERVICE_PROVISION],
                'data_sources' => ['user_input', 'system_generated'],
                'data_recipients' => ['internal_staff'],
                'necessity_justification' => 'Processing necessary for service provision',
                'proportionality_assessment' => 'Processing is proportionate to service requirements'
            ],
            'marketing_campaign' => [
                'assessment_name' => 'Marketing Campaign PIA',
                'description' => 'Privacy Impact Assessment for marketing activities',
                'data_types_processed' => [self::DATA_TYPE_CONTACT_DETAILS, self::DATA_TYPE_BEHAVIORAL_DATA],
                'processing_purposes' => [self::PURPOSE_MARKETING, self::PURPOSE_ANALYTICS],
                'data_sources' => ['user_input', 'behavioral_tracking'],
                'data_recipients' => ['marketing_team', 'external_partners'],
                'necessity_justification' => 'Processing based on legitimate interest for marketing',
                'proportionality_assessment' => 'Minimal data processing for marketing effectiveness'
            ],
            'data_analytics' => [
                'assessment_name' => 'Data Analytics PIA',
                'description' => 'Privacy Impact Assessment for analytics and reporting',
                'data_types_processed' => [self::DATA_TYPE_BEHAVIORAL_DATA, self::DATA_TYPE_PERSONAL_IDENTIFIERS],
                'processing_purposes' => [self::PURPOSE_ANALYTICS, self::PURPOSE_RESEARCH],
                'data_sources' => ['system_logs', 'user_interactions'],
                'data_recipients' => ['analytics_team', 'management'],
                'necessity_justification' => 'Processing necessary for service improvement',
                'proportionality_assessment' => 'Anonymization and aggregation used where possible'
            ]
        ];

        return $templates[$scenario] ?? $templates['new_system'];
    }

    /**
     * Get risk level colors for UI
     */
    public static function getRiskLevelColors(): array
    {
        return [
            self::RISK_LOW => '#10B981', // Green
            self::RISK_MEDIUM => '#F59E0B', // Yellow
            self::RISK_HIGH => '#EF4444', // Red
            self::RISK_VERY_HIGH => '#7C2D12', // Dark red
        ];
    }

    /**
     * Get all available options for dropdowns
     */
    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_UNDER_REVIEW => 'Under Review',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_REQUIRES_UPDATE => 'Requires Update'
        ];
    }

    public static function getRiskLevelOptions(): array
    {
        return [
            self::RISK_LOW => 'Low Risk',
            self::RISK_MEDIUM => 'Medium Risk',
            self::RISK_HIGH => 'High Risk',
            self::RISK_VERY_HIGH => 'Very High Risk'
        ];
    }

    public static function getDataTypeOptions(): array
    {
        return [
            self::DATA_TYPE_PERSONAL_IDENTIFIERS => 'Personal Identifiers',
            self::DATA_TYPE_CONTACT_DETAILS => 'Contact Details',
            self::DATA_TYPE_FINANCIAL_DATA => 'Financial Data',
            self::DATA_TYPE_HEALTH_DATA => 'Health Data',
            self::DATA_TYPE_BIOMETRIC_DATA => 'Biometric Data',
            self::DATA_TYPE_LOCATION_DATA => 'Location Data',
            self::DATA_TYPE_BEHAVIORAL_DATA => 'Behavioral Data',
            self::DATA_TYPE_SPECIAL_CATEGORIES => 'Special Categories'
        ];
    }

    public static function getProcessingPurposeOptions(): array
    {
        return [
            self::PURPOSE_SERVICE_PROVISION => 'Service Provision',
            self::PURPOSE_ANALYTICS => 'Analytics',
            self::PURPOSE_MARKETING => 'Marketing',
            self::PURPOSE_LEGAL_COMPLIANCE => 'Legal Compliance',
            self::PURPOSE_SECURITY => 'Security',
            self::PURPOSE_RESEARCH => 'Research'
        ];
    }
}