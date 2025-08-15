<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TenantResourceUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Exception;

class TenantService
{
    /**
     * Create a new tenant with complete setup
     */
    public function createTenant(array $data): Tenant
    {
        DB::beginTransaction();

        try {
            // Create the tenant
            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? Str::slug($data['name']),
                'domain' => $data['domain'] ?? null,
                'tier' => $data['tier'] ?? 'free',
                'status' => $data['status'] ?? 'pending',
                'config' => $data['config'] ?? [],
                'billing_email' => $data['billing_email'] ?? null,
                'trial_ends_at' => $data['trial_ends_at'] ?? Carbon::now()->addDays(14),
                'metadata' => $data['metadata'] ?? [],
                'created_by' => $data['created_by'] ?? null
            ]);

            // Apply tier-based limits
            $this->applyTierLimits($tenant);

            // Create the owner user if provided
            if (isset($data['owner'])) {
                $this->createTenantUser($tenant->id, array_merge($data['owner'], [
                    'role' => TenantUser::ROLE_OWNER,
                    'status' => TenantUser::STATUS_ACTIVE
                ]));
            }

            // Initialize usage tracking
            $this->initializeUsageTracking($tenant);

            // Setup default configuration
            $this->setupDefaultConfiguration($tenant);

            DB::commit();

            Log::info('Tenant created successfully', [
                'tenant_id' => $tenant->id,
                'name' => $tenant->name,
                'tier' => $tenant->tier
            ]);

            return $tenant;

        } catch (Exception $e) {
            DB::rollback();
            Log::error('Failed to create tenant', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw $e;
        }
    }

    /**
     * Create a tenant user
     */
    public function createTenantUser(string $tenantId, array $userData): TenantUser
    {
        $tenant = Tenant::findOrFail($tenantId);

        // Check if tenant has reached user limit
        if ($tenant->hasReachedUserLimit()) {
            throw new Exception('Tenant has reached maximum user limit');
        }

        $tenantUser = TenantUser::create([
            'tenant_id' => $tenantId,
            'user_id' => $userData['user_id'],
            'email' => $userData['email'],
            'name' => $userData['name'],
            'role' => $userData['role'] ?? TenantUser::ROLE_MEMBER,
            'status' => $userData['status'] ?? TenantUser::STATUS_INVITED,
            'permissions' => $userData['permissions'] ?? [],
            'invited_by' => $userData['invited_by'] ?? null,
            'metadata' => $userData['metadata'] ?? []
        ]);

        if ($tenantUser->status === TenantUser::STATUS_INVITED) {
            $tenantUser->sendInvitation($userData['invited_by'] ?? 'system');
        }

        Log::info('Tenant user created', [
            'tenant_id' => $tenantId,
            'user_id' => $tenantUser->id,
            'email' => $tenantUser->email,
            'role' => $tenantUser->role
        ]);

        return $tenantUser;
    }

    /**
     * Update tenant tier and apply new limits
     */
    public function updateTenantTier(string $tenantId, string $newTier): Tenant
    {
        $tenant = Tenant::findOrFail($tenantId);
        $oldTier = $tenant->tier;

        DB::beginTransaction();

        try {
            $tenant->upgradeTier($newTier);
            
            // Log the tier change
            Log::info('Tenant tier updated', [
                'tenant_id' => $tenantId,
                'old_tier' => $oldTier,
                'new_tier' => $newTier
            ]);

            // If downgrading, check if current usage exceeds new limits
            $this->enforceNewLimits($tenant);

            DB::commit();
            return $tenant;

        } catch (Exception $e) {
            DB::rollback();
            Log::error('Failed to update tenant tier', [
                'tenant_id' => $tenantId,
                'new_tier' => $newTier,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Suspend tenant and all related operations
     */
    public function suspendTenant(string $tenantId, string $reason): void
    {
        $tenant = Tenant::findOrFail($tenantId);
        
        $tenant->update([
            'status' => 'suspended',
            'metadata' => array_merge($tenant->metadata ?? [], [
                'suspension_reason' => $reason,
                'suspended_at' => Carbon::now()->toISOString()
            ])
        ]);

        // Suspend all active users
        $tenant->users()->where('status', TenantUser::STATUS_ACTIVE)
            ->update(['status' => TenantUser::STATUS_SUSPENDED]);

        Log::warning('Tenant suspended', [
            'tenant_id' => $tenantId,
            'reason' => $reason
        ]);
    }

    /**
     * Reactivate suspended tenant
     */
    public function reactivateTenant(string $tenantId): void
    {
        $tenant = Tenant::findOrFail($tenantId);
        
        $tenant->update([
            'status' => 'active',
            'metadata' => array_merge($tenant->metadata ?? [], [
                'reactivated_at' => Carbon::now()->toISOString()
            ])
        ]);

        // Reactivate suspended users
        $tenant->users()->where('status', TenantUser::STATUS_SUSPENDED)
            ->update(['status' => TenantUser::STATUS_ACTIVE]);

        Log::info('Tenant reactivated', ['tenant_id' => $tenantId]);
    }

    /**
     * Record resource usage for a tenant
     */
    public function recordResourceUsage(
        string $tenantId,
        string $resourceType,
        int $usage = 1,
        array $metrics = []
    ): void {
        $tenant = Tenant::findOrFail($tenantId);

        // Check if tenant can perform the action
        if (!$tenant->canPerformAction()) {
            throw new Exception('Tenant cannot perform actions due to suspension or expired subscription');
        }

        // Record the usage
        TenantResourceUsage::recordUsage($tenantId, $resourceType, $usage, $metrics);

        // Update tenant's current usage counters
        switch ($resourceType) {
            case TenantResourceUsage::RESOURCE_ORCHESTRATION_RUNS:
                $tenant->incrementUsage('orchestration_runs', $usage);
                break;
            case TenantResourceUsage::RESOURCE_TOKENS:
                $tenant->incrementUsage('tokens', $usage);
                break;
            case TenantResourceUsage::RESOURCE_STORAGE:
                $tenant->incrementUsage('storage', $usage);
                break;
        }

        // Check if tenant has exceeded quotas
        $this->checkQuotaLimits($tenant);
    }

    /**
     * Check if tenant has exceeded any quota limits
     */
    public function checkQuotaLimits(Tenant $tenant): array
    {
        $violations = [];

        if ($tenant->hasReachedOrchestrationLimit()) {
            $violations[] = 'orchestration_runs';
        }

        if ($tenant->hasReachedTokenLimit()) {
            $violations[] = 'tokens';
        }

        if ($tenant->hasReachedStorageLimit()) {
            $violations[] = 'storage';
        }

        if (!empty($violations)) {
            Log::warning('Tenant quota violations detected', [
                'tenant_id' => $tenant->id,
                'violations' => $violations
            ]);

            // Optionally suspend tenant or send notifications
            $this->handleQuotaViolations($tenant, $violations);
        }

        return $violations;
    }

    /**
     * Get tenant usage analytics
     */
    public function getTenantAnalytics(string $tenantId, Carbon $startDate = null, Carbon $endDate = null): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        $startDate = $startDate ?: Carbon::now()->startOfMonth();
        $endDate = $endDate ?: Carbon::now()->endOfMonth();

        $usage = TenantResourceUsage::forTenant($tenantId)
            ->forDateRange($startDate, $endDate)
            ->get()
            ->groupBy('resource_type');

        $analytics = [
            'tenant' => $tenant,
            'date_range' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString()
            ],
            'total_cost' => 0,
            'resources' => [],
            'daily_breakdown' => [],
            'quota_usage' => $this->getQuotaUsage($tenant),
            'performance_metrics' => []
        ];

        foreach ($usage as $resourceType => $records) {
            $resourceAnalytics = [
                'type' => $resourceType,
                'total_usage' => $records->sum('total_usage'),
                'total_cost' => $records->sum('total_cost'),
                'average_daily_usage' => $records->avg('total_usage'),
                'peak_usage' => $records->max('peak_usage'),
                'total_errors' => $records->sum('total_errors'),
                'average_error_rate' => $records->avg('error_rate_percent'),
                'average_response_time' => $records->avg('average_response_time_ms')
            ];

            $analytics['resources'][$resourceType] = $resourceAnalytics;
            $analytics['total_cost'] += $resourceAnalytics['total_cost'];
        }

        // Get daily breakdown
        $analytics['daily_breakdown'] = TenantResourceUsage::forTenant($tenantId)
            ->forDateRange($startDate, $endDate)
            ->selectRaw('usage_date, SUM(total_usage) as total_usage, SUM(total_cost) as total_cost')
            ->groupBy('usage_date')
            ->orderBy('usage_date')
            ->get()
            ->keyBy('usage_date')
            ->toArray();

        return $analytics;
    }

    /**
     * Get tenant quota usage percentages
     */
    public function getQuotaUsage(Tenant $tenant): array
    {
        return [
            'users' => [
                'current' => $tenant->current_users,
                'limit' => $tenant->max_users,
                'percentage' => $tenant->getUsagePercentage('users')
            ],
            'orchestration_runs' => [
                'current' => $tenant->current_orchestration_runs,
                'limit' => $tenant->max_orchestration_runs_per_month,
                'percentage' => $tenant->getUsagePercentage('orchestration_runs')
            ],
            'tokens' => [
                'current' => $tenant->current_tokens_used,
                'limit' => $tenant->max_tokens_per_month,
                'percentage' => $tenant->getUsagePercentage('tokens')
            ],
            'storage' => [
                'current' => $tenant->current_storage_gb,
                'limit' => $tenant->max_storage_gb,
                'percentage' => $tenant->getUsagePercentage('storage')
            ]
        ];
    }

    /**
     * Reset monthly usage counters for all tenants
     */
    public function resetMonthlyUsage(): void
    {
        $tenants = Tenant::where('usage_reset_at', '<=', Carbon::now())->get();

        foreach ($tenants as $tenant) {
            $tenant->resetMonthlyUsage();
            
            Log::info('Monthly usage reset for tenant', [
                'tenant_id' => $tenant->id,
                'name' => $tenant->name
            ]);
        }
    }

    /**
     * Cleanup expired trials and subscriptions
     */
    public function cleanupExpiredTenants(): void
    {
        // Suspend tenants with expired trials
        $expiredTrials = Tenant::where('trial_ends_at', '<', Carbon::now())
            ->where('status', 'active')
            ->whereNull('subscription_ends_at')
            ->get();

        foreach ($expiredTrials as $tenant) {
            $this->suspendTenant($tenant->id, 'Trial expired');
        }

        // Suspend tenants with expired subscriptions
        $expiredSubscriptions = Tenant::where('subscription_ends_at', '<', Carbon::now())
            ->where('status', 'active')
            ->get();

        foreach ($expiredSubscriptions as $tenant) {
            $this->suspendTenant($tenant->id, 'Subscription expired');
        }
    }

    /**
     * Get tenant billing information
     */
    public function getTenantBillingInfo(string $tenantId): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        
        $currentMonthCost = TenantResourceUsage::getTenantMonthlyCost($tenantId);
        $resourceBreakdown = TenantResourceUsage::getTenantResourceBreakdown($tenantId);
        
        return [
            'tenant' => $tenant,
            'current_month_cost' => $currentMonthCost,
            'resource_breakdown' => $resourceBreakdown,
            'subscription_status' => $this->getSubscriptionStatus($tenant),
            'next_billing_date' => $this->getNextBillingDate($tenant),
            'payment_method' => $this->getPaymentMethodInfo($tenant)
        ];
    }

    /**
     * Apply tier-based limits to tenant
     */
    private function applyTierLimits(Tenant $tenant): void
    {
        $limits = $tenant->getTierLimits();
        
        foreach ($limits as $key => $value) {
            if ($value !== -1) { // -1 means unlimited
                $tenant->{$key} = $value;
            }
        }
        
        $tenant->save();
    }

    /**
     * Initialize usage tracking for new tenant
     */
    private function initializeUsageTracking(Tenant $tenant): void
    {
        $tenant->update([
            'usage_reset_at' => Carbon::now()->addMonth()
        ]);
    }

    /**
     * Setup default configuration for new tenant
     */
    private function setupDefaultConfiguration(Tenant $tenant): void
    {
        $defaultConfig = [
            'features' => [
                'api_access' => true,
                'analytics' => true,
                'custom_agents' => $tenant->tier !== 'free',
                'priority_support' => in_array($tenant->tier, ['professional', 'enterprise']),
                'sso' => $tenant->tier === 'enterprise'
            ],
            'notifications' => [
                'quota_warnings' => true,
                'billing_alerts' => true,
                'security_alerts' => true
            ],
            'security' => [
                'session_timeout' => 3600, // 1 hour
                'password_policy' => 'standard',
                'mfa_required' => $tenant->tier === 'enterprise'
            ]
        ];

        $tenant->update([
            'config' => array_merge($tenant->config ?? [], $defaultConfig)
        ]);
    }

    /**
     * Enforce new limits when tier is changed
     */
    private function enforceNewLimits(Tenant $tenant): void
    {
        // If current usage exceeds new limits, log warnings
        $violations = $this->checkQuotaLimits($tenant);
        
        if (!empty($violations)) {
            // For downgrades, you might want to implement grace periods
            // or force cleanup of resources
            Log::warning('Tenant exceeds new tier limits after downgrade', [
                'tenant_id' => $tenant->id,
                'violations' => $violations
            ]);
        }
    }

    /**
     * Handle quota violations
     */
    private function handleQuotaViolations(Tenant $tenant, array $violations): void
    {
        // Implementation depends on your business logic
        // You might want to:
        // 1. Send notifications
        // 2. Temporarily suspend services
        // 3. Force upgrade
        // 4. Apply overage charges

        foreach ($violations as $violation) {
            Log::warning('Quota violation', [
                'tenant_id' => $tenant->id,
                'resource' => $violation,
                'current_usage' => $tenant->{"current_{$violation}"},
                'limit' => $tenant->{"max_{$violation}"}
            ]);
        }
    }

    /**
     * Get subscription status
     */
    private function getSubscriptionStatus(Tenant $tenant): string
    {
        if ($tenant->isTrialExpired() && !$tenant->subscription_ends_at) {
            return 'trial_expired';
        }

        if ($tenant->isSubscriptionExpired()) {
            return 'subscription_expired';
        }

        if ($tenant->trial_ends_at && $tenant->trial_ends_at->isFuture()) {
            return 'trial';
        }

        if ($tenant->subscription_ends_at && $tenant->subscription_ends_at->isFuture()) {
            return 'active';
        }

        return 'unknown';
    }

    /**
     * Get next billing date
     */
    private function getNextBillingDate(Tenant $tenant): ?Carbon
    {
        if ($tenant->subscription_ends_at) {
            return $tenant->subscription_ends_at->addMonth();
        }

        return null;
    }

    /**
     * Get payment method information
     */
    private function getPaymentMethodInfo(Tenant $tenant): ?array
    {
        // This would integrate with your payment processor (Stripe, etc.)
        // For now, return basic info
        
        if ($tenant->stripe_customer_id) {
            return [
                'provider' => 'stripe',
                'customer_id' => $tenant->stripe_customer_id,
                'has_payment_method' => !empty($tenant->stripe_subscription_id)
            ];
        }

        return null;
    }
}