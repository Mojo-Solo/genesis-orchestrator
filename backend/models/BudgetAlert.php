<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Carbon\Carbon;

class BudgetAlert extends Model
{
    use HasUuids;

    protected $fillable = [
        'budget_id',
        'tenant_id',
        'alert_type',
        'threshold_percentage',
        'severity',
        'title',
        'message',
        'alert_data',
        'current_spend',
        'budget_amount',
        'utilization_percentage',
        'period_start',
        'period_end',
        'status',
        'triggered_at',
        'acknowledged_at',
        'resolved_at',
        'acknowledged_by',
        'resolved_by',
        'resolution_notes',
        'notification_channels',
        'notification_status',
        'notification_attempts',
        'last_notification_at'
    ];

    protected $casts = [
        'threshold_percentage' => 'decimal:2',
        'current_spend' => 'decimal:2',
        'budget_amount' => 'decimal:2',
        'utilization_percentage' => 'decimal:2',
        'period_start' => 'date',
        'period_end' => 'date',
        'triggered_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'last_notification_at' => 'datetime',
        'alert_data' => 'array',
        'notification_channels' => 'array',
        'notification_status' => 'array',
        'notification_attempts' => 'integer'
    ];

    // Alert type constants
    const TYPE_THRESHOLD = 'threshold';
    const TYPE_FORECAST = 'forecast';
    const TYPE_ANOMALY = 'anomaly';
    const TYPE_EXPIRY = 'expiry';

    // Severity constants
    const SEVERITY_LOW = 'low';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_HIGH = 'high';
    const SEVERITY_CRITICAL = 'critical';

    // Status constants
    const STATUS_ACTIVE = 'active';
    const STATUS_ACKNOWLEDGED = 'acknowledged';
    const STATUS_RESOLVED = 'resolved';
    const STATUS_SUPPRESSED = 'suppressed';

    // Relationships
    public function budget(): BelongsTo
    {
        return $this->belongsTo(TenantBudget::class, 'budget_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeUnacknowledged($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
                    ->whereNull('acknowledged_at');
    }

    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForBudget($query, $budgetId)
    {
        return $query->where('budget_id', $budgetId);
    }

    public function scopeBySeverity($query, $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('alert_type', $type);
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    public function scopeRecentlyTriggered($query, $hours = 24)
    {
        return $query->where('triggered_at', '>=', Carbon::now()->subHours($hours));
    }

    public function scopePendingNotification($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
                    ->where(function ($q) {
                        $q->whereNull('last_notification_at')
                          ->orWhere('notification_attempts', 0);
                    });
    }

    // Alert lifecycle methods
    public function acknowledge(string $acknowledgedBy = null, string $notes = null): bool
    {
        return $this->update([
            'status' => self::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => Carbon::now(),
            'acknowledged_by' => $acknowledgedBy,
            'resolution_notes' => $notes
        ]);
    }

    public function resolve(string $resolvedBy = null, string $notes = null): bool
    {
        return $this->update([
            'status' => self::STATUS_RESOLVED,
            'resolved_at' => Carbon::now(),
            'resolved_by' => $resolvedBy,
            'resolution_notes' => $notes
        ]);
    }

    public function suppress(string $reason = null): bool
    {
        $alertData = $this->alert_data ?? [];
        $alertData['suppression_reason'] = $reason;
        
        return $this->update([
            'status' => self::STATUS_SUPPRESSED,
            'alert_data' => $alertData
        ]);
    }

    public function reactivate(): bool
    {
        return $this->update([
            'status' => self::STATUS_ACTIVE,
            'acknowledged_at' => null,
            'resolved_at' => null,
            'acknowledged_by' => null,
            'resolved_by' => null
        ]);
    }

    // Notification methods
    public function markNotificationSent(string $channel, bool $success = true, string $error = null): void
    {
        $notificationStatus = $this->notification_status ?? [];
        $notificationStatus[$channel] = [
            'sent_at' => Carbon::now()->toISOString(),
            'success' => $success,
            'error' => $error,
            'attempt' => $this->notification_attempts + 1
        ];

        $this->update([
            'notification_status' => $notificationStatus,
            'notification_attempts' => $this->notification_attempts + 1,
            'last_notification_at' => Carbon::now()
        ]);
    }

    public function shouldRetryNotification(): bool
    {
        // Retry logic: max 3 attempts, with exponential backoff
        if ($this->notification_attempts >= 3) {
            return false;
        }

        if (!$this->last_notification_at) {
            return true;
        }

        $nextRetryTime = $this->last_notification_at->addMinutes(
            pow(2, $this->notification_attempts) * 5 // 5, 10, 20 minutes
        );

        return Carbon::now()->isAfter($nextRetryTime);
    }

    public function getNotificationChannels(): array
    {
        $channels = $this->notification_channels ?? [];
        
        // Default channels based on severity
        if (empty($channels)) {
            $channels = $this->getDefaultChannelsForSeverity();
        }

        return $channels;
    }

    private function getDefaultChannelsForSeverity(): array
    {
        return match ($this->severity) {
            self::SEVERITY_CRITICAL => ['email', 'slack', 'webhook'],
            self::SEVERITY_HIGH => ['email', 'slack'],
            self::SEVERITY_MEDIUM => ['email'],
            self::SEVERITY_LOW => ['email'],
            default => ['email']
        };
    }

    // Alert analysis methods
    public function getTimeSinceTriggered(): \DateInterval
    {
        return $this->triggered_at->diff(Carbon::now());
    }

    public function getResolutionTime(): ?\DateInterval
    {
        if (!$this->resolved_at) {
            return null;
        }

        return $this->triggered_at->diff($this->resolved_at);
    }

    public function isStale(int $hours = 72): bool
    {
        return $this->triggered_at->isBefore(Carbon::now()->subHours($hours)) 
               && $this->status === self::STATUS_ACTIVE;
    }

    public function isEscalated(): bool
    {
        $escalationThresholds = [
            self::SEVERITY_LOW => 24,     // 24 hours
            self::SEVERITY_MEDIUM => 12,  // 12 hours
            self::SEVERITY_HIGH => 4,     // 4 hours
            self::SEVERITY_CRITICAL => 1  // 1 hour
        ];

        $threshold = $escalationThresholds[$this->severity] ?? 24;
        
        return $this->triggered_at->isBefore(Carbon::now()->subHours($threshold))
               && $this->status === self::STATUS_ACTIVE;
    }

    public function getSeverityScore(): int
    {
        return match ($this->severity) {
            self::SEVERITY_CRITICAL => 4,
            self::SEVERITY_HIGH => 3,
            self::SEVERITY_MEDIUM => 2,
            self::SEVERITY_LOW => 1,
            default => 0
        };
    }

    public function getImpactAssessment(): array
    {
        $impact = [
            'financial' => 'medium',
            'operational' => 'low',
            'compliance' => 'low',
            'urgency' => $this->severity
        ];

        // Assess based on alert type and data
        if ($this->alert_type === self::TYPE_THRESHOLD) {
            $threshold = $this->threshold_percentage ?? 0;
            if ($threshold >= 100) {
                $impact['financial'] = 'high';
                $impact['operational'] = 'medium';
            } elseif ($threshold >= 90) {
                $impact['financial'] = 'medium';
            }
        }

        if ($this->alert_type === self::TYPE_FORECAST) {
            $impact['financial'] = 'high';
            $impact['operational'] = 'medium';
        }

        return $impact;
    }

    // Static factory methods
    public static function createThresholdAlert(
        string $budgetId,
        string $tenantId,
        float $threshold,
        float $currentUtilization,
        array $additionalData = []
    ): self {
        return self::create([
            'budget_id' => $budgetId,
            'tenant_id' => $tenantId,
            'alert_type' => self::TYPE_THRESHOLD,
            'threshold_percentage' => $threshold,
            'severity' => self::calculateThresholdSeverity($threshold),
            'title' => "Budget {$threshold}% threshold exceeded",
            'message' => "Budget has reached {$currentUtilization}% utilization",
            'alert_data' => array_merge([
                'threshold' => $threshold,
                'current_utilization' => $currentUtilization
            ], $additionalData),
            'utilization_percentage' => $currentUtilization,
            'triggered_at' => Carbon::now()
        ]);
    }

    public static function createAnomalyAlert(
        string $budgetId,
        string $tenantId,
        string $anomalyType,
        array $anomalyData
    ): self {
        return self::create([
            'budget_id' => $budgetId,
            'tenant_id' => $tenantId,
            'alert_type' => self::TYPE_ANOMALY,
            'severity' => self::SEVERITY_MEDIUM,
            'title' => "Cost anomaly detected: {$anomalyType}",
            'message' => "Unusual spending pattern detected in budget",
            'alert_data' => $anomalyData,
            'triggered_at' => Carbon::now()
        ]);
    }

    private static function calculateThresholdSeverity(float $threshold): string
    {
        if ($threshold >= 100) {
            return self::SEVERITY_CRITICAL;
        } elseif ($threshold >= 90) {
            return self::SEVERITY_HIGH;
        } elseif ($threshold >= 75) {
            return self::SEVERITY_MEDIUM;
        } else {
            return self::SEVERITY_LOW;
        }
    }

    // Alert aggregation methods
    public static function getAlertsForDashboard(string $tenantId): array
    {
        $alerts = self::forTenant($tenantId)
            ->active()
            ->orderBy('severity', 'desc')
            ->orderBy('triggered_at', 'desc')
            ->limit(10)
            ->get();

        return [
            'alerts' => $alerts,
            'counts' => [
                'total' => $alerts->count(),
                'critical' => $alerts->where('severity', self::SEVERITY_CRITICAL)->count(),
                'high' => $alerts->where('severity', self::SEVERITY_HIGH)->count(),
                'unacknowledged' => $alerts->whereNull('acknowledged_at')->count()
            ],
            'summary' => [
                'most_severe' => $alerts->first()?->severity,
                'oldest_unresolved' => $alerts->where('status', self::STATUS_ACTIVE)->min('triggered_at'),
                'escalated_count' => $alerts->filter->isEscalated()->count()
            ]
        ];
    }

    public static function getAlertTrends(string $tenantId, int $days = 30): array
    {
        $trends = self::forTenant($tenantId)
            ->where('triggered_at', '>=', Carbon::now()->subDays($days))
            ->selectRaw('DATE(triggered_at) as date, severity, COUNT(*) as count')
            ->groupBy('date', 'severity')
            ->orderBy('date')
            ->get()
            ->groupBy('date');

        $trendData = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $dayAlerts = $trends[$date] ?? collect();
            
            $trendData[] = [
                'date' => $date,
                'total' => $dayAlerts->sum('count'),
                'critical' => $dayAlerts->where('severity', self::SEVERITY_CRITICAL)->sum('count'),
                'high' => $dayAlerts->where('severity', self::SEVERITY_HIGH)->sum('count'),
                'medium' => $dayAlerts->where('severity', self::SEVERITY_MEDIUM)->sum('count'),
                'low' => $dayAlerts->where('severity', self::SEVERITY_LOW)->sum('count')
            ];
        }

        return $trendData;
    }

    // Export methods
    public function toArray(): array
    {
        $array = parent::toArray();
        
        // Add calculated fields
        $array['time_since_triggered'] = $this->getTimeSinceTriggered()->format('%h hours %i minutes');
        $array['resolution_time'] = $this->getResolutionTime()?->format('%h hours %i minutes');
        $array['is_stale'] = $this->isStale();
        $array['is_escalated'] = $this->isEscalated();
        $array['severity_score'] = $this->getSeverityScore();
        $array['impact_assessment'] = $this->getImpactAssessment();
        $array['should_retry_notification'] = $this->shouldRetryNotification();
        
        return $array;
    }
}