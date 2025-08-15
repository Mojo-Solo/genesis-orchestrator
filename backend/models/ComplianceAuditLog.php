<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class ComplianceAuditLog extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'event_type',
        'compliance_area',
        'severity',
        'data_subject_id',
        'data_subject_type',
        'event_description',
        'event_data',
        'legal_basis',
        'source_system',
        'source_ip',
        'user_agent',
        'performed_by',
        'performed_at',
        'automated_action',
        'workflow_id'
    ];

    protected $casts = [
        'event_data' => 'array',
        'legal_basis' => 'array',
        'performed_at' => 'datetime',
        'automated_action' => 'boolean'
    ];

    // Event types
    const EVENT_CONSENT_GRANTED = 'consent_granted';
    const EVENT_CONSENT_WITHDRAWN = 'consent_withdrawn';
    const EVENT_CONSENT_EXPIRED = 'consent_expired';
    const EVENT_DATA_ACCESSED = 'data_accessed';
    const EVENT_DATA_EXPORTED = 'data_exported';
    const EVENT_DATA_DELETED = 'data_deleted';
    const EVENT_DATA_ANONYMIZED = 'data_anonymized';
    const EVENT_DATA_RECTIFIED = 'data_rectified';
    const EVENT_PROCESSING_RESTRICTED = 'processing_restricted';
    const EVENT_DSR_CREATED = 'dsr_created';
    const EVENT_DSR_PROCESSED = 'dsr_processed';
    const EVENT_DSR_STATUS_CHANGED = 'dsr_status_changed';
    const EVENT_RETENTION_POLICY_APPLIED = 'retention_policy_applied';
    const EVENT_RETENTION_EXECUTION_STARTED = 'retention_execution_started';
    const EVENT_RETENTION_EXECUTION_COMPLETED = 'retention_execution_completed';
    const EVENT_RETENTION_EXECUTION_FAILED = 'retention_execution_failed';
    const EVENT_RETENTION_POLICY_CREATED = 'retention_policy_created';
    const EVENT_RETENTION_POLICY_APPROVED = 'retention_policy_approved';
    const EVENT_PRIVACY_SETTING_CHANGED = 'privacy_setting_changed';
    const EVENT_PIA_CONDUCTED = 'pia_conducted';
    const EVENT_DATA_BREACH_DETECTED = 'data_breach_detected';
    const EVENT_DATA_BREACH_REPORTED = 'data_breach_reported';

    // Compliance areas
    const AREA_GDPR = 'gdpr';
    const AREA_CCPA = 'ccpa';
    const AREA_HIPAA = 'hipaa';
    const AREA_SOX = 'sox';
    const AREA_PCI_DSS = 'pci_dss';
    const AREA_ISO27001 = 'iso27001';
    const AREA_INTERNAL = 'internal';

    // Severity levels
    const SEVERITY_INFO = 'info';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_ERROR = 'error';
    const SEVERITY_CRITICAL = 'critical';

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

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'performed_by');
    }

    /**
     * Scopes
     */
    public function scopeByTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeByEventType($query, $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    public function scopeByComplianceArea($query, $area)
    {
        return $query->where('compliance_area', $area);
    }

    public function scopeBySeverity($query, $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeByDataSubject($query, $dataSubjectId, $type = 'tenant_user')
    {
        return $query->where('data_subject_id', $dataSubjectId)
                    ->where('data_subject_type', $type);
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    public function scopeWarningsAndErrors($query)
    {
        return $query->whereIn('severity', [self::SEVERITY_WARNING, self::SEVERITY_ERROR, self::SEVERITY_CRITICAL]);
    }

    public function scopeRecent($query, $hours = 24)
    {
        return $query->where('performed_at', '>=', Carbon::now()->subHours($hours));
    }

    public function scopeAutomated($query)
    {
        return $query->where('automated_action', true);
    }

    public function scopeManual($query)
    {
        return $query->where('automated_action', false);
    }

    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('performed_at', [$startDate, $endDate]);
    }

    /**
     * Boot method to set defaults
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($log) {
            if (empty($log->performed_at)) {
                $log->performed_at = Carbon::now();
            }
            
            if (empty($log->source_system)) {
                $log->source_system = 'genesis_orchestrator';
            }
            
            if (empty($log->source_ip) && request()) {
                $log->source_ip = request()->ip();
            }
            
            if (empty($log->user_agent) && request()) {
                $log->user_agent = request()->userAgent();
            }
        });
    }

    /**
     * Log a compliance event
     */
    public static function logEvent(
        string $eventType,
        string $complianceArea,
        string $severity,
        $dataSubjectId = null,
        string $dataSubjectType = null,
        string $description = null,
        array $eventData = [],
        $tenantId = null,
        array $legalBasis = [],
        $performedBy = null,
        bool $automated = false,
        string $workflowId = null
    ): self {
        return self::create([
            'tenant_id' => $tenantId ?: (auth()->user()?->tenant_id ?? null),
            'event_type' => $eventType,
            'compliance_area' => $complianceArea,
            'severity' => $severity,
            'data_subject_id' => $dataSubjectId,
            'data_subject_type' => $dataSubjectType ?: 'tenant_user',
            'event_description' => $description,
            'event_data' => $eventData,
            'legal_basis' => $legalBasis,
            'performed_by' => $performedBy ?: auth()->id(),
            'automated_action' => $automated,
            'workflow_id' => $workflowId
        ]);
    }

    /**
     * Log consent granted event
     */
    public static function logConsentGranted($consentId, $dataSubjectId, $consentType, $tenantId): self
    {
        return self::logEvent(
            self::EVENT_CONSENT_GRANTED,
            self::AREA_GDPR,
            self::SEVERITY_INFO,
            $dataSubjectId,
            'tenant_user',
            "Consent granted for {$consentType}",
            ['consent_id' => $consentId, 'consent_type' => $consentType],
            $tenantId,
            ['basis' => 'consent', 'article' => 'Article 6(1)(a)']
        );
    }

    /**
     * Log consent withdrawal event
     */
    public static function logConsentWithdrawn($consentId, $dataSubjectId, $consentType, $tenantId): self
    {
        return self::logEvent(
            self::EVENT_CONSENT_WITHDRAWN,
            self::AREA_GDPR,
            self::SEVERITY_WARNING,
            $dataSubjectId,
            'tenant_user',
            "Consent withdrawn for {$consentType}",
            ['consent_id' => $consentId, 'consent_type' => $consentType],
            $tenantId,
            ['basis' => 'consent_withdrawal', 'article' => 'Article 7(3)']
        );
    }

    /**
     * Log data access event
     */
    public static function logDataAccess($dataSubjectId, $dataType, $accessReason, $tenantId): self
    {
        return self::logEvent(
            self::EVENT_DATA_ACCESSED,
            self::AREA_GDPR,
            self::SEVERITY_INFO,
            $dataSubjectId,
            'tenant_user',
            "Personal data accessed: {$dataType}",
            ['data_type' => $dataType, 'access_reason' => $accessReason],
            $tenantId,
            ['basis' => 'legitimate_interest', 'article' => 'Article 6(1)(f)']
        );
    }

    /**
     * Log data export event
     */
    public static function logDataExport($dataSubjectId, $exportType, $filePath, $tenantId): self
    {
        return self::logEvent(
            self::EVENT_DATA_EXPORTED,
            self::AREA_GDPR,
            self::SEVERITY_INFO,
            $dataSubjectId,
            'tenant_user',
            "Personal data exported: {$exportType}",
            ['export_type' => $exportType, 'file_path' => basename($filePath)],
            $tenantId,
            ['basis' => 'data_portability', 'article' => 'Article 20']
        );
    }

    /**
     * Log data deletion event
     */
    public static function logDataDeletion($dataSubjectId, $dataType, $reason, $tenantId): self
    {
        return self::logEvent(
            self::EVENT_DATA_DELETED,
            self::AREA_GDPR,
            self::SEVERITY_WARNING,
            $dataSubjectId,
            'tenant_user',
            "Personal data deleted: {$dataType}",
            ['data_type' => $dataType, 'deletion_reason' => $reason],
            $tenantId,
            ['basis' => 'erasure', 'article' => 'Article 17']
        );
    }

    /**
     * Get compliance summary for a time period
     */
    public static function getComplianceSummary($tenantId, $startDate, $endDate): array
    {
        $logs = self::byTenant($tenantId)
            ->dateRange($startDate, $endDate)
            ->get();

        $summary = [
            'total_events' => $logs->count(),
            'by_severity' => [
                self::SEVERITY_INFO => 0,
                self::SEVERITY_WARNING => 0,
                self::SEVERITY_ERROR => 0,
                self::SEVERITY_CRITICAL => 0
            ],
            'by_compliance_area' => [],
            'by_event_type' => [],
            'automated_vs_manual' => [
                'automated' => 0,
                'manual' => 0
            ],
            'data_subject_activity' => [],
            'timeline' => []
        ];

        foreach ($logs as $log) {
            // Count by severity
            $summary['by_severity'][$log->severity]++;

            // Count by compliance area
            if (!isset($summary['by_compliance_area'][$log->compliance_area])) {
                $summary['by_compliance_area'][$log->compliance_area] = 0;
            }
            $summary['by_compliance_area'][$log->compliance_area]++;

            // Count by event type
            if (!isset($summary['by_event_type'][$log->event_type])) {
                $summary['by_event_type'][$log->event_type] = 0;
            }
            $summary['by_event_type'][$log->event_type]++;

            // Count automated vs manual
            if ($log->automated_action) {
                $summary['automated_vs_manual']['automated']++;
            } else {
                $summary['automated_vs_manual']['manual']++;
            }

            // Track data subject activity
            if ($log->data_subject_id) {
                if (!isset($summary['data_subject_activity'][$log->data_subject_id])) {
                    $summary['data_subject_activity'][$log->data_subject_id] = 0;
                }
                $summary['data_subject_activity'][$log->data_subject_id]++;
            }

            // Build timeline (by day)
            $day = $log->performed_at->format('Y-m-d');
            if (!isset($summary['timeline'][$day])) {
                $summary['timeline'][$day] = 0;
            }
            $summary['timeline'][$day]++;
        }

        return $summary;
    }

    /**
     * Get audit trail for a specific data subject
     */
    public static function getDataSubjectAuditTrail($dataSubjectId, $tenantId, $type = 'tenant_user'): array
    {
        $logs = self::byTenant($tenantId)
            ->byDataSubject($dataSubjectId, $type)
            ->orderBy('performed_at', 'desc')
            ->get();

        return $logs->map(function ($log) {
            return [
                'timestamp' => $log->performed_at,
                'event_type' => $log->event_type,
                'description' => $log->event_description,
                'severity' => $log->severity,
                'compliance_area' => $log->compliance_area,
                'automated' => $log->automated_action,
                'legal_basis' => $log->legal_basis,
                'event_data' => $log->event_data
            ];
        })->toArray();
    }

    /**
     * Get critical compliance events requiring attention
     */
    public static function getCriticalEvents($tenantId, $hours = 24): array
    {
        return self::byTenant($tenantId)
            ->critical()
            ->recent($hours)
            ->orderBy('performed_at', 'desc')
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'timestamp' => $log->performed_at,
                    'event_type' => $log->event_type,
                    'description' => $log->event_description,
                    'compliance_area' => $log->compliance_area,
                    'data_subject_id' => $log->data_subject_id,
                    'automated' => $log->automated_action,
                    'event_data' => $log->event_data
                ];
            })
            ->toArray();
    }

    /**
     * Get all event types
     */
    public static function getEventTypes(): array
    {
        return [
            self::EVENT_CONSENT_GRANTED => 'Consent Granted',
            self::EVENT_CONSENT_WITHDRAWN => 'Consent Withdrawn',
            self::EVENT_CONSENT_EXPIRED => 'Consent Expired',
            self::EVENT_DATA_ACCESSED => 'Data Accessed',
            self::EVENT_DATA_EXPORTED => 'Data Exported',
            self::EVENT_DATA_DELETED => 'Data Deleted',
            self::EVENT_DATA_ANONYMIZED => 'Data Anonymized',
            self::EVENT_DATA_RECTIFIED => 'Data Rectified',
            self::EVENT_PROCESSING_RESTRICTED => 'Processing Restricted',
            self::EVENT_DSR_CREATED => 'Data Subject Request Created',
            self::EVENT_DSR_PROCESSED => 'Data Subject Request Processed',
            self::EVENT_DSR_STATUS_CHANGED => 'Data Subject Request Status Changed',
            self::EVENT_RETENTION_POLICY_APPLIED => 'Retention Policy Applied',
            self::EVENT_PRIVACY_SETTING_CHANGED => 'Privacy Setting Changed',
            self::EVENT_PIA_CONDUCTED => 'Privacy Impact Assessment Conducted',
            self::EVENT_DATA_BREACH_DETECTED => 'Data Breach Detected',
            self::EVENT_DATA_BREACH_REPORTED => 'Data Breach Reported'
        ];
    }

    /**
     * Get all compliance areas
     */
    public static function getComplianceAreas(): array
    {
        return [
            self::AREA_GDPR => 'General Data Protection Regulation',
            self::AREA_CCPA => 'California Consumer Privacy Act',
            self::AREA_HIPAA => 'Health Insurance Portability and Accountability Act',
            self::AREA_SOX => 'Sarbanes-Oxley Act',
            self::AREA_PCI_DSS => 'Payment Card Industry Data Security Standard',
            self::AREA_ISO27001 => 'ISO 27001 Information Security',
            self::AREA_INTERNAL => 'Internal Compliance'
        ];
    }
}