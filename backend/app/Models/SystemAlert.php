<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Events\SystemAlertResolved;

/**
 * System Alert Model
 * 
 * Manages system alerts and notifications for monitoring
 */
class SystemAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'severity',
        'message',
        'context',
        'status',
        'trace_id',
        'tenant_id',
        'acknowledged_by',
        'acknowledged_at',
        'resolved_at',
        'resolution_notes',
    ];

    protected $casts = [
        'context' => 'array',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    const SEVERITY_INFO = 'info';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_CRITICAL = 'critical';

    const STATUS_ACTIVE = 'active';
    const STATUS_ACKNOWLEDGED = 'acknowledged';
    const STATUS_RESOLVED = 'resolved';
    const STATUS_SUPPRESSED = 'suppressed';

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    /**
     * Get active alerts
     */
    public static function getActiveAlerts(?int $tenantId = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = self::where('status', self::STATUS_ACTIVE)
            ->orderBy('severity')
            ->orderBy('created_at', 'desc');

        if ($tenantId) {
            $query->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)
                  ->orWhereNull('tenant_id');
            });
        }

        return $query->get();
    }

    /**
     * Get critical alerts count
     */
    public static function getCriticalAlertsCount(?int $tenantId = null): int
    {
        $query = self::where('status', self::STATUS_ACTIVE)
            ->where('severity', self::SEVERITY_CRITICAL);

        if ($tenantId) {
            $query->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)
                  ->orWhereNull('tenant_id');
            });
        }

        return $query->count();
    }

    /**
     * Acknowledge alert
     */
    public function acknowledge(int $userId, string $notes = null): bool
    {
        $this->update([
            'status' => self::STATUS_ACKNOWLEDGED,
            'acknowledged_by' => $userId,
            'acknowledged_at' => now(),
        ]);

        if ($notes) {
            $this->addNote('acknowledgment', $notes, $userId);
        }

        return true;
    }

    /**
     * Resolve alert
     */
    public function resolve(int $userId, string $resolutionNotes = null): bool
    {
        $this->update([
            'status' => self::STATUS_RESOLVED,
            'resolved_at' => now(),
            'resolution_notes' => $resolutionNotes,
        ]);

        $this->addNote('resolution', $resolutionNotes, $userId);

        event(new SystemAlertResolved($this));

        return true;
    }

    /**
     * Suppress alert
     */
    public function suppress(int $userId, string $reason, int $durationMinutes = null): bool
    {
        $this->update([
            'status' => self::STATUS_SUPPRESSED,
        ]);

        $this->addNote('suppression', $reason, $userId);

        // Schedule automatic unsuppression if duration specified
        if ($durationMinutes) {
            // Implementation would use job scheduling
        }

        return true;
    }

    /**
     * Add note to alert
     */
    public function addNote(string $type, string $content, int $userId): void
    {
        $context = $this->context ?? [];
        $context['notes'] = $context['notes'] ?? [];
        
        $context['notes'][] = [
            'type' => $type,
            'content' => $content,
            'user_id' => $userId,
            'timestamp' => now()->toISOString(),
        ];

        $this->update(['context' => $context]);
    }

    /**
     * Get alert priority score for sorting
     */
    public function getPriorityScore(): int
    {
        $severityScores = [
            self::SEVERITY_CRITICAL => 100,
            self::SEVERITY_WARNING => 50,
            self::SEVERITY_INFO => 10,
        ];

        $baseScore = $severityScores[$this->severity] ?? 0;
        
        // Add recency bonus (newer alerts get higher priority)
        $ageHours = $this->created_at->diffInHours(now());
        $recencyBonus = max(0, 50 - $ageHours);

        return $baseScore + $recencyBonus;
    }

    /**
     * Check if alert is duplicate
     */
    public static function isDuplicate(string $type, array $context, int $timeWindowMinutes = 15): bool
    {
        $cutoffTime = now()->subMinutes($timeWindowMinutes);
        
        return self::where('type', $type)
            ->where('status', self::STATUS_ACTIVE)
            ->where('created_at', '>=', $cutoffTime)
            ->whereJsonContains('context', $context)
            ->exists();
    }

    /**
     * Auto-resolve alerts based on conditions
     */
    public static function autoResolveAlerts(): int
    {
        $resolved = 0;

        // Auto-resolve old info alerts
        $oldInfoAlerts = self::where('severity', self::SEVERITY_INFO)
            ->where('status', self::STATUS_ACTIVE)
            ->where('created_at', '<', now()->subHours(24))
            ->get();

        foreach ($oldInfoAlerts as $alert) {
            $alert->resolve(0, 'Auto-resolved: Old info alert');
            $resolved++;
        }

        // Auto-resolve alerts with resolution conditions
        $conditionalAlerts = self::where('status', self::STATUS_ACTIVE)
            ->whereNotNull('context->auto_resolve_condition')
            ->get();

        foreach ($conditionalAlerts as $alert) {
            if ($alert->shouldAutoResolve()) {
                $alert->resolve(0, 'Auto-resolved: Condition met');
                $resolved++;
            }
        }

        return $resolved;
    }

    /**
     * Check if alert should auto-resolve
     */
    public function shouldAutoResolve(): bool
    {
        $condition = $this->context['auto_resolve_condition'] ?? null;
        
        if (!$condition) {
            return false;
        }

        // Implementation would check various conditions
        // (metric values, service health, etc.)
        return false;
    }

    /**
     * Get alert statistics
     */
    public static function getStatistics(
        \DateTime $startTime,
        \DateTime $endTime,
        ?int $tenantId = null
    ): array {
        $query = self::whereBetween('created_at', [$startTime, $endTime]);
        
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $alerts = $query->get();

        return [
            'total' => $alerts->count(),
            'by_severity' => [
                'critical' => $alerts->where('severity', self::SEVERITY_CRITICAL)->count(),
                'warning' => $alerts->where('severity', self::SEVERITY_WARNING)->count(),
                'info' => $alerts->where('severity', self::SEVERITY_INFO)->count(),
            ],
            'by_status' => [
                'active' => $alerts->where('status', self::STATUS_ACTIVE)->count(),
                'acknowledged' => $alerts->where('status', self::STATUS_ACKNOWLEDGED)->count(),
                'resolved' => $alerts->where('status', self::STATUS_RESOLVED)->count(),
                'suppressed' => $alerts->where('status', self::STATUS_SUPPRESSED)->count(),
            ],
            'resolution_time' => [
                'avg_minutes' => $alerts->where('status', self::STATUS_RESOLVED)
                    ->avg(function ($alert) {
                        return $alert->created_at->diffInMinutes($alert->resolved_at);
                    }),
                'median_minutes' => $alerts->where('status', self::STATUS_RESOLVED)
                    ->map(function ($alert) {
                        return $alert->created_at->diffInMinutes($alert->resolved_at);
                    })
                    ->median(),
            ],
            'top_alert_types' => $alerts->groupBy('type')
                ->map(function ($group) {
                    return $group->count();
                })
                ->sortDesc()
                ->take(10)
                ->toArray(),
        ];
    }

    /**
     * Relationship: User who acknowledged the alert
     */
    public function acknowledgedBy()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * Relationship: Tenant the alert belongs to
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Scope: Active alerts only
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope: Critical alerts only
     */
    public function scopeCritical($query)
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    /**
     * Scope: Recent alerts (last 24 hours)
     */
    public function scopeRecent($query)
    {
        return $query->where('created_at', '>=', now()->subDay());
    }
}