<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;
use Carbon\Carbon;

class Tenant extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'domain',
        'config',
        'status',
        'tier',
        'max_users',
        'max_orchestration_runs_per_month',
        'max_tokens_per_month',
        'max_storage_gb',
        'max_api_calls_per_minute',
        'current_users',
        'current_orchestration_runs',
        'current_tokens_used',
        'current_storage_gb',
        'usage_reset_at',
        'billing_email',
        'stripe_customer_id',
        'stripe_subscription_id',
        'trial_ends_at',
        'subscription_ends_at',
        'allowed_ip_ranges',
        'enforce_mfa',
        'sso_enabled',
        'sso_config',
        'metadata',
        'created_by'
    ];

    protected $casts = [
        'config' => 'array',
        'allowed_ip_ranges' => 'array',
        'sso_config' => 'array',
        'metadata' => 'array',
        'enforce_mfa' => 'boolean',
        'sso_enabled' => 'boolean',
        'usage_reset_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'subscription_ends_at' => 'datetime',
        'current_storage_gb' => 'decimal:2'
    ];

    protected $dates = [
        'usage_reset_at',
        'trial_ends_at',
        'subscription_ends_at',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    // Relationships
    public function users(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function resourceUsage(): HasMany
    {
        return $this->hasMany(TenantResourceUsage::class);
    }

    public function orchestrationRuns(): HasMany
    {
        return $this->hasMany(OrchestrationRun::class);
    }

    public function agentExecutions(): HasMany
    {
        return $this->hasMany(AgentExecution::class);
    }

    public function memoryItems(): HasMany
    {
        return $this->hasMany(MemoryItem::class);
    }

    public function routerMetrics(): HasMany
    {
        return $this->hasMany(RouterMetric::class);
    }

    public function stabilityTracking(): HasMany
    {
        return $this->hasMany(StabilityTracking::class);
    }

    public function securityAuditLogs(): HasMany
    {
        return $this->hasMany(SecurityAuditLog::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByTier($query, $tier)
    {
        return $query->where('tier', $tier);
    }

    public function scopeTrialExpiring($query, $days = 7)
    {
        return $query->where('trial_ends_at', '<=', Carbon::now()->addDays($days))
                    ->where('trial_ends_at', '>', Carbon::now());
    }

    public function scopeSubscriptionExpiring($query, $days = 7)
    {
        return $query->where('subscription_ends_at', '<=', Carbon::now()->addDays($days))
                    ->where('subscription_ends_at', '>', Carbon::now());
    }

    // Business Logic Methods
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isTrialExpired(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isPast();
    }

    public function isSubscriptionExpired(): bool
    {
        return $this->subscription_ends_at && $this->subscription_ends_at->isPast();
    }

    public function canPerformAction(): bool
    {
        return $this->isActive() && !$this->isTrialExpired() && !$this->isSubscriptionExpired();
    }

    // Quota Management
    public function hasReachedUserLimit(): bool
    {
        return $this->current_users >= $this->max_users;
    }

    public function hasReachedOrchestrationLimit(): bool
    {
        return $this->current_orchestration_runs >= $this->max_orchestration_runs_per_month;
    }

    public function hasReachedTokenLimit(): bool
    {
        return $this->current_tokens_used >= $this->max_tokens_per_month;
    }

    public function hasReachedStorageLimit(): bool
    {
        return $this->current_storage_gb >= $this->max_storage_gb;
    }

    public function getRemainingQuota(string $resource): int
    {
        switch ($resource) {
            case 'users':
                return max(0, $this->max_users - $this->current_users);
            case 'orchestration_runs':
                return max(0, $this->max_orchestration_runs_per_month - $this->current_orchestration_runs);
            case 'tokens':
                return max(0, $this->max_tokens_per_month - $this->current_tokens_used);
            case 'storage':
                return max(0, $this->max_storage_gb - $this->current_storage_gb);
            default:
                return 0;
        }
    }

    public function getUsagePercentage(string $resource): float
    {
        switch ($resource) {
            case 'users':
                return $this->max_users > 0 ? ($this->current_users / $this->max_users) * 100 : 0;
            case 'orchestration_runs':
                return $this->max_orchestration_runs_per_month > 0 ? 
                    ($this->current_orchestration_runs / $this->max_orchestration_runs_per_month) * 100 : 0;
            case 'tokens':
                return $this->max_tokens_per_month > 0 ? 
                    ($this->current_tokens_used / $this->max_tokens_per_month) * 100 : 0;
            case 'storage':
                return $this->max_storage_gb > 0 ? 
                    ($this->current_storage_gb / $this->max_storage_gb) * 100 : 0;
            default:
                return 0;
        }
    }

    // Usage Tracking
    public function incrementUsage(string $resource, int $amount = 1): void
    {
        switch ($resource) {
            case 'users':
                $this->increment('current_users', $amount);
                break;
            case 'orchestration_runs':
                $this->increment('current_orchestration_runs', $amount);
                break;
            case 'tokens':
                $this->increment('current_tokens_used', $amount);
                break;
            case 'storage':
                $this->increment('current_storage_gb', $amount);
                break;
        }
    }

    public function decrementUsage(string $resource, int $amount = 1): void
    {
        switch ($resource) {
            case 'users':
                $this->decrement('current_users', $amount);
                break;
            case 'orchestration_runs':
                $this->decrement('current_orchestration_runs', $amount);
                break;
            case 'tokens':
                $this->decrement('current_tokens_used', $amount);
                break;
            case 'storage':
                $this->decrement('current_storage_gb', $amount);
                break;
        }
    }

    public function resetMonthlyUsage(): void
    {
        $this->update([
            'current_orchestration_runs' => 0,
            'current_tokens_used' => 0,
            'usage_reset_at' => Carbon::now()->addMonth()
        ]);
    }

    // Security
    public function isIpAllowed(string $ip): bool
    {
        if (empty($this->allowed_ip_ranges)) {
            return true; // No restrictions
        }

        foreach ($this->allowed_ip_ranges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private function ipInRange(string $ip, string $range): bool
    {
        if (strpos($range, '/') !== false) {
            // CIDR notation
            list($subnet, $bits) = explode('/', $range);
            $ip = ip2long($ip);
            $subnet = ip2long($subnet);
            $mask = -1 << (32 - $bits);
            $subnet &= $mask;
            return ($ip & $mask) == $subnet;
        } else {
            // Single IP
            return $ip === $range;
        }
    }

    // Tier Management
    public function getTierLimits(): array
    {
        $limits = [
            'free' => [
                'max_users' => 5,
                'max_orchestration_runs_per_month' => 1000,
                'max_tokens_per_month' => 100000,
                'max_storage_gb' => 10,
                'max_api_calls_per_minute' => 100
            ],
            'starter' => [
                'max_users' => 25,
                'max_orchestration_runs_per_month' => 10000,
                'max_tokens_per_month' => 1000000,
                'max_storage_gb' => 100,
                'max_api_calls_per_minute' => 500
            ],
            'professional' => [
                'max_users' => 100,
                'max_orchestration_runs_per_month' => 50000,
                'max_tokens_per_month' => 5000000,
                'max_storage_gb' => 500,
                'max_api_calls_per_minute' => 2000
            ],
            'enterprise' => [
                'max_users' => -1, // Unlimited
                'max_orchestration_runs_per_month' => -1,
                'max_tokens_per_month' => -1,
                'max_storage_gb' => -1,
                'max_api_calls_per_minute' => 10000
            ]
        ];

        return $limits[$this->tier] ?? $limits['free'];
    }

    public function upgradeTier(string $newTier): void
    {
        $this->tier = $newTier;
        $limits = $this->getTierLimits();
        
        foreach ($limits as $key => $value) {
            if ($value !== -1) { // -1 means unlimited
                $this->{$key} = $value;
            }
        }
        
        $this->save();
    }

    // Utility Methods
    public function generateSlug(): void
    {
        $baseSlug = Str::slug($this->name);
        $slug = $baseSlug;
        $counter = 1;

        while (static::where('slug', $slug)->where('id', '!=', $this->id)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        $this->slug = $slug;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($tenant) {
            if (empty($tenant->slug)) {
                $tenant->generateSlug();
            }
        });

        static::updating(function ($tenant) {
            if ($tenant->isDirty('name') && !$tenant->isDirty('slug')) {
                $tenant->generateSlug();
            }
        });
    }
}