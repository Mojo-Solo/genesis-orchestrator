<?php

namespace App\Domains\TenantManagement;

use App\Domains\TenantManagement\Contracts\TenantInterface;
use App\Domains\TenantManagement\Services\TenantIsolation;
use App\Domains\TenantManagement\Services\ResourceQuotas;
use App\Domains\TenantManagement\Services\BillingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Tenant Management Domain Service
 * 
 * Consolidates multi-tenant architecture, resource management, and billing.
 * Ensures complete tenant isolation and proper resource quota enforcement.
 * 
 * Consolidates 5 previous services:
 * - TenantService
 * - BillingService  
 * - FinOpsService
 * - CostOptimizationService
 * - FinancialReportingService
 */
class TenantDomain implements TenantInterface
{
    private TenantIsolation $isolation;
    private ResourceQuotas $quotas;
    private BillingService $billing;
    
    private array $config = [
        'isolation' => [
            'strict_mode' => true,
            'data_encryption' => true,
            'network_isolation' => true
        ],
        'quotas' => [
            'default_limits' => [
                'users' => 5,
                'projects' => 10,
                'api_calls_per_hour' => 1000,
                'storage_gb' => 1
            ]
        ],
        'billing' => [
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'payment_grace_period_days' => 7
        ]
    ];
    
    public function __construct(
        TenantIsolation $isolation,
        ResourceQuotas $quotas,
        BillingService $billing
    ) {
        $this->isolation = $isolation;
        $this->quotas = $quotas;
        $this->billing = $billing;
    }
    
    /**
     * Create new tenant with complete isolation setup
     */
    public function createTenant(array $tenantData): array
    {
        return DB::transaction(function () use ($tenantData) {
            // Create tenant record
            $tenant = $this->isolation->createTenant($tenantData);
            
            // Set up resource quotas
            $quotas = $this->quotas->initializeTenantQuotas($tenant['id'], $tenantData['tier'] ?? 'free');
            
            // Initialize billing
            $billing = $this->billing->initializeBilling($tenant['id'], $tenantData);
            
            return [
                'tenant' => $tenant,
                'quotas' => $quotas,
                'billing' => $billing,
                'status' => 'created'
            ];
        });
    }
    
    /**
     * Get tenant with full context
     */
    public function getTenant(string $tenantId): array
    {
        $tenant = $this->isolation->getTenant($tenantId);
        $quotas = $this->quotas->getTenantQuotas($tenantId);
        $billing = $this->billing->getTenantBilling($tenantId);
        
        return [
            'tenant' => $tenant,
            'quotas' => $quotas,
            'billing' => $billing,
            'usage' => $this->quotas->getCurrentUsage($tenantId)
        ];
    }
    
    /**
     * Enforce resource quotas for tenant action
     */
    public function enforceQuotas(string $tenantId, string $resource, int $amount = 1): bool
    {
        return $this->quotas->checkAndEnforce($tenantId, $resource, $amount);
    }
    
    /**
     * Update tenant billing information
     */
    public function updateBilling(string $tenantId, array $billingData): array
    {
        return $this->billing->updateBilling($tenantId, $billingData);
    }
    
    public function getTenantsHealth(): array
    {
        return [
            'total_tenants' => $this->isolation->getTotalTenants(),
            'active_tenants' => $this->isolation->getActiveTenants(),
            'quota_violations' => $this->quotas->getQuotaViolations(),
            'billing_issues' => $this->billing->getBillingIssues()
        ];
    }
}