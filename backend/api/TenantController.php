<?php

namespace App\Controllers;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class TenantController
{
    protected TenantService $tenantService;

    public function __construct(TenantService $tenantService)
    {
        $this->tenantService = $tenantService;
    }

    /**
     * Create a new tenant
     */
    public function create(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:tenants,slug',
            'domain' => 'nullable|string|max:255|unique:tenants,domain',
            'tier' => 'nullable|in:free,starter,professional,enterprise',
            'billing_email' => 'nullable|email',
            'trial_days' => 'nullable|integer|min:0|max:365',
            'owner.user_id' => 'required|string',
            'owner.email' => 'required|email',
            'owner.name' => 'required|string|max:255',
            'config' => 'nullable|array',
            'metadata' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $request->all();
            
            // Set trial end date if trial_days provided
            if (isset($data['trial_days'])) {
                $data['trial_ends_at'] = Carbon::now()->addDays($data['trial_days']);
            }

            $tenant = $this->tenantService->createTenant($data);

            return response()->json([
                'message' => 'Tenant created successfully',
                'tenant' => $tenant->load('users')
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create tenant', [
                'error' => $e->getMessage(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'error' => 'Failed to create tenant',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get tenant information
     */
    public function show(Request $request, string $tenantId = null): JsonResponse
    {
        try {
            // Use current tenant if no ID provided
            $tenantId = $tenantId ?: $request->get('tenant_id');
            
            if (!$tenantId) {
                return response()->json([
                    'error' => 'Tenant ID not provided'
                ], 400);
            }

            $tenant = Tenant::with(['users' => function ($query) {
                $query->where('status', '!=', TenantUser::STATUS_DELETED);
            }])->findOrFail($tenantId);

            // Get additional tenant information
            $quotaUsage = $this->tenantService->getQuotaUsage($tenant);
            $analytics = $this->tenantService->getTenantAnalytics($tenantId);
            $billingInfo = $this->tenantService->getTenantBillingInfo($tenantId);

            return response()->json([
                'tenant' => $tenant,
                'quota_usage' => $quotaUsage,
                'analytics' => $analytics,
                'billing' => $billingInfo
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve tenant', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to retrieve tenant',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update tenant information
     */
    public function update(Request $request, string $tenantId = null): JsonResponse
    {
        $tenantId = $tenantId ?: $request->get('tenant_id');
        
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'domain' => 'sometimes|string|max:255|unique:tenants,domain,' . $tenantId,
            'billing_email' => 'sometimes|email',
            'config' => 'sometimes|array',
            'allowed_ip_ranges' => 'sometimes|array',
            'enforce_mfa' => 'sometimes|boolean',
            'sso_enabled' => 'sometimes|boolean',
            'sso_config' => 'sometimes|array',
            'metadata' => 'sometimes|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $tenant = Tenant::findOrFail($tenantId);
            
            // Check permissions
            $currentUser = $request->get('tenant_user');
            if (!$currentUser->hasPermission(TenantUser::PERMISSION_MANAGE_USERS)) {
                return response()->json([
                    'error' => 'Insufficient permissions'
                ], 403);
            }

            $tenant->update($request->only([
                'name', 'domain', 'billing_email', 'config', 
                'allowed_ip_ranges', 'enforce_mfa', 'sso_enabled', 
                'sso_config', 'metadata'
            ]));

            return response()->json([
                'message' => 'Tenant updated successfully',
                'tenant' => $tenant
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update tenant', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to update tenant',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update tenant tier
     */
    public function updateTier(Request $request, string $tenantId = null): JsonResponse
    {
        $tenantId = $tenantId ?: $request->get('tenant_id');
        
        $validator = Validator::make($request->all(), [
            'tier' => 'required|in:free,starter,professional,enterprise'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Check permissions
            $currentUser = $request->get('tenant_user');
            if (!$currentUser->hasPermission(TenantUser::PERMISSION_MANAGE_BILLING)) {
                return response()->json([
                    'error' => 'Insufficient permissions'
                ], 403);
            }

            $tenant = $this->tenantService->updateTenantTier($tenantId, $request->tier);

            return response()->json([
                'message' => 'Tenant tier updated successfully',
                'tenant' => $tenant,
                'new_limits' => $tenant->getTierLimits()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update tenant tier', [
                'tenant_id' => $tenantId,
                'new_tier' => $request->tier,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to update tenant tier',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Suspend tenant
     */
    public function suspend(Request $request, string $tenantId = null): JsonResponse
    {
        $tenantId = $tenantId ?: $request->get('tenant_id');
        
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Check permissions (only owners can suspend their own tenant)
            $currentUser = $request->get('tenant_user');
            if (!$currentUser->isOwner()) {
                return response()->json([
                    'error' => 'Only tenant owners can suspend the tenant'
                ], 403);
            }

            $this->tenantService->suspendTenant($tenantId, $request->reason);

            return response()->json([
                'message' => 'Tenant suspended successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to suspend tenant', [
                'tenant_id' => $tenantId,
                'reason' => $request->reason,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to suspend tenant',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reactivate tenant
     */
    public function reactivate(Request $request, string $tenantId = null): JsonResponse
    {
        $tenantId = $tenantId ?: $request->get('tenant_id');

        try {
            // Check permissions (only owners can reactivate their own tenant)
            $currentUser = $request->get('tenant_user');
            if (!$currentUser->isOwner()) {
                return response()->json([
                    'error' => 'Only tenant owners can reactivate the tenant'
                ], 403);
            }

            $this->tenantService->reactivateTenant($tenantId);

            return response()->json([
                'message' => 'Tenant reactivated successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to reactivate tenant', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to reactivate tenant',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get tenant users
     */
    public function users(Request $request, string $tenantId = null): JsonResponse
    {
        $tenantId = $tenantId ?: $request->get('tenant_id');

        try {
            $users = TenantUser::where('tenant_id', $tenantId)
                ->where('status', '!=', TenantUser::STATUS_DELETED)
                ->orderBy('role')
                ->orderBy('name')
                ->get();

            return response()->json([
                'users' => $users
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve tenant users', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to retrieve tenant users',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add user to tenant
     */
    public function addUser(Request $request, string $tenantId = null): JsonResponse
    {
        $tenantId = $tenantId ?: $request->get('tenant_id');
        
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|string',
            'email' => 'required|email',
            'name' => 'required|string|max:255',
            'role' => 'required|in:owner,admin,member,viewer',
            'permissions' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Check permissions
            $currentUser = $request->get('tenant_user');
            if (!$currentUser->hasPermission(TenantUser::PERMISSION_MANAGE_USERS)) {
                return response()->json([
                    'error' => 'Insufficient permissions'
                ], 403);
            }

            // Check if user can manage the requested role
            $requestedRole = $request->role;
            if ($requestedRole === TenantUser::ROLE_OWNER && !$currentUser->isOwner()) {
                return response()->json([
                    'error' => 'Only owners can create other owners'
                ], 403);
            }

            if ($requestedRole === TenantUser::ROLE_ADMIN && !$currentUser->hasRoleOrHigher(TenantUser::ROLE_ADMIN)) {
                return response()->json([
                    'error' => 'Insufficient role level to create admin users'
                ], 403);
            }

            $userData = $request->only(['user_id', 'email', 'name', 'role', 'permissions']);
            $userData['invited_by'] = $currentUser->user_id;

            $tenantUser = $this->tenantService->createTenantUser($tenantId, $userData);

            return response()->json([
                'message' => 'User added to tenant successfully',
                'user' => $tenantUser
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to add user to tenant', [
                'tenant_id' => $tenantId,
                'user_data' => $request->only(['user_id', 'email', 'name', 'role']),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to add user to tenant',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update tenant user
     */
    public function updateUser(Request $request, string $tenantId = null, string $userId = null): JsonResponse
    {
        $tenantId = $tenantId ?: $request->get('tenant_id');
        
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'role' => 'sometimes|in:owner,admin,member,viewer',
            'permissions' => 'sometimes|array',
            'status' => 'sometimes|in:active,suspended'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $tenantUser = TenantUser::where('tenant_id', $tenantId)
                ->where('id', $userId)
                ->firstOrFail();

            // Check permissions
            $currentUser = $request->get('tenant_user');
            if (!$currentUser->canManageUser($tenantUser)) {
                return response()->json([
                    'error' => 'Insufficient permissions to manage this user'
                ], 403);
            }

            $tenantUser->update($request->only(['name', 'role', 'permissions', 'status']));

            return response()->json([
                'message' => 'User updated successfully',
                'user' => $tenantUser
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update tenant user', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to update user',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove user from tenant
     */
    public function removeUser(Request $request, string $tenantId = null, string $userId = null): JsonResponse
    {
        $tenantId = $tenantId ?: $request->get('tenant_id');

        try {
            $tenantUser = TenantUser::where('tenant_id', $tenantId)
                ->where('id', $userId)
                ->firstOrFail();

            // Check permissions
            $currentUser = $request->get('tenant_user');
            if (!$currentUser->canManageUser($tenantUser)) {
                return response()->json([
                    'error' => 'Insufficient permissions to remove this user'
                ], 403);
            }

            // Prevent removing the last owner
            if ($tenantUser->isOwner()) {
                $ownerCount = TenantUser::where('tenant_id', $tenantId)
                    ->where('role', TenantUser::ROLE_OWNER)
                    ->where('status', TenantUser::STATUS_ACTIVE)
                    ->count();

                if ($ownerCount <= 1) {
                    return response()->json([
                        'error' => 'Cannot remove the last owner from tenant'
                    ], 422);
                }
            }

            $tenantUser->update(['status' => TenantUser::STATUS_DELETED]);

            return response()->json([
                'message' => 'User removed from tenant successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to remove user from tenant', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to remove user',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get tenant analytics
     */
    public function analytics(Request $request, string $tenantId = null): JsonResponse
    {
        $tenantId = $tenantId ?: $request->get('tenant_id');
        
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'resource_type' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Check permissions
            $currentUser = $request->get('tenant_user');
            if (!$currentUser->hasPermission(TenantUser::PERMISSION_VIEW_ANALYTICS)) {
                return response()->json([
                    'error' => 'Insufficient permissions'
                ], 403);
            }

            $startDate = $request->start_date ? Carbon::parse($request->start_date) : null;
            $endDate = $request->end_date ? Carbon::parse($request->end_date) : null;

            $analytics = $this->tenantService->getTenantAnalytics($tenantId, $startDate, $endDate);

            return response()->json($analytics);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve tenant analytics', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to retrieve analytics',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get tenant billing information
     */
    public function billing(Request $request, string $tenantId = null): JsonResponse
    {
        $tenantId = $tenantId ?: $request->get('tenant_id');

        try {
            // Check permissions
            $currentUser = $request->get('tenant_user');
            if (!$currentUser->hasPermission(TenantUser::PERMISSION_MANAGE_BILLING)) {
                return response()->json([
                    'error' => 'Insufficient permissions'
                ], 403);
            }

            $billingInfo = $this->tenantService->getTenantBillingInfo($tenantId);

            return response()->json($billingInfo);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve tenant billing info', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to retrieve billing information',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get quota usage
     */
    public function quotas(Request $request, string $tenantId = null): JsonResponse
    {
        $tenantId = $tenantId ?: $request->get('tenant_id');

        try {
            $tenant = Tenant::findOrFail($tenantId);
            $quotaUsage = $this->tenantService->getQuotaUsage($tenant);

            return response()->json([
                'quotas' => $quotaUsage,
                'tier' => $tenant->tier,
                'tier_limits' => $tenant->getTierLimits(),
                'usage_reset_at' => $tenant->usage_reset_at
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve quota usage', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to retrieve quota usage',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * List all tenants (admin only)
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'status' => 'nullable|in:active,suspended,pending,deleted',
            'tier' => 'nullable|in:free,starter,professional,enterprise',
            'search' => 'nullable|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $query = Tenant::with(['users' => function ($query) {
                $query->where('status', '!=', TenantUser::STATUS_DELETED);
            }]);

            // Apply filters
            if ($request->status) {
                $query->where('status', $request->status);
            }

            if ($request->tier) {
                $query->where('tier', $request->tier);
            }

            if ($request->search) {
                $query->where(function ($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->search . '%')
                      ->orWhere('slug', 'like', '%' . $request->search . '%')
                      ->orWhere('domain', 'like', '%' . $request->search . '%');
                });
            }

            $perPage = min($request->per_page ?? 20, 100);
            $tenants = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json($tenants);

        } catch (\Exception $e) {
            Log::error('Failed to list tenants', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to list tenants',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}