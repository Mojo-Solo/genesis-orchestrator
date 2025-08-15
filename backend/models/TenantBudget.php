<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class TenantBudget extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'budget_name',
        'budget_type',
        'scope',
        'scope_value',
        'budget_amount',
        'currency',
        'spent_amount',
        'committed_amount',
        'remaining_amount',
        'start_date',
        'end_date',
        'auto_renew',
        'alert_thresholds',
        'alert_recipients',
        'enforce_hard_limit',
        'status',
        'metadata',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'budget_amount' => 'decimal:2',
        'spent_amount' => 'decimal:2',
        'committed_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'auto_renew' => 'boolean',
        'enforce_hard_limit' => 'boolean',
        'alert_thresholds' => 'array',
        'alert_recipients' => 'array',
        'metadata' => 'array'
    ];

    // Budget type constants
    const TYPE_MONTHLY = 'monthly';
    const TYPE_QUARTERLY = 'quarterly';
    const TYPE_ANNUAL = 'annual';
    const TYPE_CUSTOM = 'custom';

    // Scope constants
    const SCOPE_TENANT = 'tenant';
    const SCOPE_DEPARTMENT = 'department';
    const SCOPE_USER = 'user';
    const SCOPE_RESOURCE_TYPE = 'resource_type';

    // Status constants
    const STATUS_ACTIVE = 'active';
    const STATUS_SUSPENDED = 'suspended';
    const STATUS_EXPIRED = 'expired';
    const STATUS_DRAFT = 'draft';

    // Default alert thresholds
    const DEFAULT_THRESHOLDS = [50, 75, 90, 100];

    // Relationships
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(BudgetAlert::class, 'budget_id');
    }

    public function forecasts(): HasMany
    {
        return $this->hasMany(BudgetForecast::class, 'budget_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeExpired($query)
    {
        return $query->where('end_date', '<', Carbon::today());
    }

    public function scopeExpiringSoon($query, $days = 30)
    {
        return $query->where('end_date', '<=', Carbon::today()->addDays($days))
                    ->where('end_date', '>=', Carbon::today());
    }

    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForScope($query, $scope, $scopeValue = null)
    {
        $query = $query->where('scope', $scope);
        
        if ($scopeValue !== null) {
            $query->where('scope_value', $scopeValue);
        }
        
        return $query;
    }

    public function scopeCurrentPeriod($query)
    {
        $today = Carbon::today();
        return $query->where('start_date', '<=', $today)
                    ->where('end_date', '>=', $today);
    }

    public function scopeOverBudget($query)
    {
        return $query->whereRaw('spent_amount > budget_amount');
    }

    public function scopeNearingLimit($query, $thresholdPercentage = 90)
    {
        return $query->whereRaw('(spent_amount / budget_amount) * 100 >= ?', [$thresholdPercentage]);
    }

    // Budget lifecycle methods
    public static function createBudget(array $data): self
    {
        $budget = new self($data);
        
        // Set default alert thresholds if not provided
        if (empty($budget->alert_thresholds)) {
            $budget->alert_thresholds = self::DEFAULT_THRESHOLDS;
        }
        
        // Calculate remaining amount
        $budget->remaining_amount = $budget->budget_amount - $budget->spent_amount - $budget->committed_amount;
        
        $budget->save();
        
        return $budget;
    }

    public function updateSpending(float $amount, bool $isCommitted = false): void
    {
        if ($isCommitted) {
            $this->committed_amount += $amount;
        } else {
            $this->spent_amount += $amount;
            // If we're moving from committed to actual, reduce committed amount
            if ($this->committed_amount > 0) {
                $this->committed_amount = max(0, $this->committed_amount - $amount);
            }
        }
        
        $this->remaining_amount = $this->budget_amount - $this->spent_amount - $this->committed_amount;
        $this->save();
        
        // Check for budget alerts
        $this->checkBudgetThresholds();
    }

    public function getUtilizationPercentage(): float
    {
        if ($this->budget_amount <= 0) {
            return 0;
        }
        
        return round(($this->spent_amount / $this->budget_amount) * 100, 2);
    }

    public function getProjectedUtilizationPercentage(): float
    {
        if ($this->budget_amount <= 0) {
            return 0;
        }
        
        $totalProjected = $this->spent_amount + $this->committed_amount;
        return round(($totalProjected / $this->budget_amount) * 100, 2);
    }

    public function getRemainingDays(): int
    {
        return Carbon::today()->diffInDays($this->end_date, false);
    }

    public function getDaysElapsed(): int
    {
        return $this->start_date->diffInDays(Carbon::today());
    }

    public function getBudgetPeriodDays(): int
    {
        return $this->start_date->diffInDays($this->end_date);
    }

    public function getAverageDailySpend(): float
    {
        $daysElapsed = max(1, $this->getDaysElapsed());
        return round($this->spent_amount / $daysElapsed, 2);
    }

    public function getProjectedTotalSpend(): float
    {
        $averageDailySpend = $this->getAverageDailySpend();
        $totalDays = $this->getBudgetPeriodDays();
        return round($averageDailySpend * $totalDays, 2);
    }

    public function getBudgetRunRate(): float
    {
        $remainingDays = max(1, $this->getRemainingDays());
        return round($this->remaining_amount / $remainingDays, 2);
    }

    public function isOverBudget(): bool
    {
        return $this->spent_amount > $this->budget_amount;
    }

    public function isProjectedOverBudget(): bool
    {
        return $this->getProjectedTotalSpend() > $this->budget_amount;
    }

    public function isExpired(): bool
    {
        return Carbon::today()->isAfter($this->end_date);
    }

    public function isExpiringSoon($days = 30): bool
    {
        return Carbon::today()->addDays($days)->isAfter($this->end_date) && !$this->isExpired();
    }

    public function hasHardLimitEnforcement(): bool
    {
        return $this->enforce_hard_limit;
    }

    public function canSpend(float $amount): bool
    {
        if (!$this->hasHardLimitEnforcement()) {
            return true;
        }
        
        return ($this->spent_amount + $amount) <= $this->budget_amount;
    }

    // Alert management
    public function checkBudgetThresholds(): array
    {
        $alerts = [];
        $utilizationPercentage = $this->getUtilizationPercentage();
        
        foreach ($this->alert_thresholds as $threshold) {
            if ($utilizationPercentage >= $threshold && !$this->hasRecentAlert($threshold)) {
                $alert = $this->triggerBudgetAlert($threshold, $utilizationPercentage);
                $alerts[] = $alert;
            }
        }
        
        // Check for projected overrun
        if ($this->isProjectedOverBudget() && !$this->hasRecentAlert('projected_overrun')) {
            $alert = $this->triggerProjectedOverrunAlert();
            $alerts[] = $alert;
        }
        
        return $alerts;
    }

    private function hasRecentAlert(string $alertType): bool
    {
        return $this->alerts()
            ->where('alert_type', 'threshold')
            ->where('alert_data->threshold_type', $alertType)
            ->where('triggered_at', '>=', Carbon::now()->subHours(24))
            ->exists();
    }

    private function triggerBudgetAlert(float $threshold, float $currentUtilization): BudgetAlert
    {
        return BudgetAlert::create([
            'budget_id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'alert_type' => 'threshold',
            'threshold_percentage' => $threshold,
            'severity' => $this->calculateSeverity($threshold),
            'title' => "Budget {$threshold}% threshold exceeded",
            'message' => "Budget '{$this->budget_name}' has reached {$currentUtilization}% utilization",
            'alert_data' => [
                'threshold_type' => $threshold,
                'budget_name' => $this->budget_name,
                'current_utilization' => $currentUtilization,
                'spent_amount' => $this->spent_amount,
                'budget_amount' => $this->budget_amount,
                'remaining_amount' => $this->remaining_amount
            ],
            'current_spend' => $this->spent_amount,
            'budget_amount' => $this->budget_amount,
            'utilization_percentage' => $currentUtilization,
            'period_start' => $this->start_date,
            'period_end' => $this->end_date,
            'triggered_at' => Carbon::now()
        ]);
    }

    private function triggerProjectedOverrunAlert(): BudgetAlert
    {
        $projectedSpend = $this->getProjectedTotalSpend();
        $overrunAmount = $projectedSpend - $this->budget_amount;
        
        return BudgetAlert::create([
            'budget_id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'alert_type' => 'forecast',
            'severity' => 'high',
            'title' => "Projected budget overrun detected",
            'message' => "Budget '{$this->budget_name}' is projected to exceed by \${$overrunAmount}",
            'alert_data' => [
                'threshold_type' => 'projected_overrun',
                'budget_name' => $this->budget_name,
                'projected_spend' => $projectedSpend,
                'budget_amount' => $this->budget_amount,
                'overrun_amount' => $overrunAmount,
                'remaining_days' => $this->getRemainingDays()
            ],
            'current_spend' => $this->spent_amount,
            'budget_amount' => $this->budget_amount,
            'utilization_percentage' => $this->getUtilizationPercentage(),
            'period_start' => $this->start_date,
            'period_end' => $this->end_date,
            'triggered_at' => Carbon::now()
        ]);
    }

    private function calculateSeverity(float $threshold): string
    {
        if ($threshold >= 100) {
            return 'critical';
        } elseif ($threshold >= 90) {
            return 'high';
        } elseif ($threshold >= 75) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    // Budget renewal
    public function renewBudget(): ?self
    {
        if (!$this->auto_renew || !$this->isExpired()) {
            return null;
        }
        
        $newStartDate = $this->end_date->addDay();
        $newEndDate = $this->calculateNewEndDate($newStartDate);
        
        $newBudget = self::create([
            'tenant_id' => $this->tenant_id,
            'budget_name' => $this->budget_name,
            'budget_type' => $this->budget_type,
            'scope' => $this->scope,
            'scope_value' => $this->scope_value,
            'budget_amount' => $this->budget_amount,
            'currency' => $this->currency,
            'start_date' => $newStartDate,
            'end_date' => $newEndDate,
            'auto_renew' => $this->auto_renew,
            'alert_thresholds' => $this->alert_thresholds,
            'alert_recipients' => $this->alert_recipients,
            'enforce_hard_limit' => $this->enforce_hard_limit,
            'metadata' => $this->metadata,
            'created_by' => 'system_renewal'
        ]);
        
        // Mark this budget as expired
        $this->update(['status' => self::STATUS_EXPIRED]);
        
        return $newBudget;
    }

    private function calculateNewEndDate(Carbon $startDate): Carbon
    {
        switch ($this->budget_type) {
            case self::TYPE_MONTHLY:
                return $startDate->copy()->addMonth()->subDay();
            case self::TYPE_QUARTERLY:
                return $startDate->copy()->addMonths(3)->subDay();
            case self::TYPE_ANNUAL:
                return $startDate->copy()->addYear()->subDay();
            default:
                // For custom budgets, use the same duration
                $duration = $this->start_date->diffInDays($this->end_date);
                return $startDate->copy()->addDays($duration);
        }
    }

    // Analytics and reporting
    public function getSpendingTrend(int $days = 30): array
    {
        // This would integrate with TenantResourceUsage to get daily spending
        // For now, return a simple calculation
        $averageDaily = $this->getAverageDailySpend();
        $trend = [];
        
        for ($i = 0; $i < $days; $i++) {
            $date = Carbon::today()->subDays($days - $i - 1);
            $trend[$date->format('Y-m-d')] = $averageDaily; // Simplified
        }
        
        return $trend;
    }

    public function getVarianceAnalysis(): array
    {
        $projectedSpend = $this->getProjectedTotalSpend();
        $variance = $projectedSpend - $this->budget_amount;
        $variancePercentage = $this->budget_amount > 0 ? ($variance / $this->budget_amount) * 100 : 0;
        
        return [
            'budget_amount' => $this->budget_amount,
            'projected_spend' => $projectedSpend,
            'variance_amount' => $variance,
            'variance_percentage' => round($variancePercentage, 2),
            'status' => $variance > 0 ? 'over_budget' : 'under_budget'
        ];
    }

    public function getHealthScore(): float
    {
        $utilizationPenalty = max(0, $this->getUtilizationPercentage() - 80) * 0.5;
        $runRatePenalty = $this->isProjectedOverBudget() ? 20 : 0;
        $timePenalty = $this->getRemainingDays() < 7 ? 10 : 0;
        
        $healthScore = 100 - $utilizationPenalty - $runRatePenalty - $timePenalty;
        
        return max(0, min(100, round($healthScore, 1)));
    }

    // Static utility methods
    public static function getActiveBudgetsForTenant(string $tenantId): \Illuminate\Database\Eloquent\Collection
    {
        return self::forTenant($tenantId)
            ->active()
            ->currentPeriod()
            ->orderBy('budget_name')
            ->get();
    }

    public static function getTotalBudgetAmount(string $tenantId): float
    {
        return self::forTenant($tenantId)
            ->active()
            ->currentPeriod()
            ->sum('budget_amount');
    }

    public static function getTotalSpentAmount(string $tenantId): float
    {
        return self::forTenant($tenantId)
            ->active()
            ->currentPeriod()
            ->sum('spent_amount');
    }

    public static function getBudgetsNeedingAttention(string $tenantId): \Illuminate\Database\Eloquent\Collection
    {
        return self::forTenant($tenantId)
            ->active()
            ->currentPeriod()
            ->where(function ($query) {
                $query->nearingLimit(85)
                      ->orWhere(function ($q) {
                          $q->whereRaw('(spent_amount / budget_amount) * 100 >= 90');
                      });
            })
            ->get();
    }

    // Data export methods
    public function toArray(): array
    {
        $array = parent::toArray();
        
        // Add calculated fields
        $array['utilization_percentage'] = $this->getUtilizationPercentage();
        $array['projected_utilization_percentage'] = $this->getProjectedUtilizationPercentage();
        $array['remaining_days'] = $this->getRemainingDays();
        $array['average_daily_spend'] = $this->getAverageDailySpend();
        $array['projected_total_spend'] = $this->getProjectedTotalSpend();
        $array['is_over_budget'] = $this->isOverBudget();
        $array['is_projected_over_budget'] = $this->isProjectedOverBudget();
        $array['health_score'] = $this->getHealthScore();
        
        return $array;
    }
}