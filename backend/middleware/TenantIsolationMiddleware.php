<?php

namespace App\Middleware;

use App\Models\Tenant;
use App\Models\TenantUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

class TenantIsolationMiddleware
{
    /**
     * Handle an incoming request and enforce tenant isolation
     */
    public function handle(Request $request, Closure $next): BaseResponse
    {
        try {
            // Extract tenant information from request
            $tenantContext = $this->resolveTenantContext($request);
            
            if (!$tenantContext) {
                return $this->unauthorizedResponse('Invalid or missing tenant context');
            }

            // Validate tenant and user
            $validationResult = $this->validateTenantAccess($tenantContext, $request);
            
            if (!$validationResult['valid']) {
                return $this->unauthorizedResponse($validationResult['message']);
            }

            // Set tenant context in request for downstream use
            $request->merge([
                'tenant_context' => $tenantContext,
                'tenant_id' => $tenantContext['tenant']->id,
                'tenant_user' => $tenantContext['user']
            ]);

            // Add tenant context to all database queries
            $this->setTenantScope($tenantContext['tenant']->id);

            // Log the request for audit purposes
            $this->logTenantRequest($request, $tenantContext);

            $response = $next($request);

            // Add tenant information to response headers (for debugging)
            if (config('app.debug')) {
                $response->headers->set('X-Tenant-ID', $tenantContext['tenant']->id);
                $response->headers->set('X-Tenant-Name', $tenantContext['tenant']->name);
            }

            return $response;

        } catch (\Exception $e) {
            Log::error('Tenant isolation middleware error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_path' => $request->path(),
                'request_method' => $request->method()
            ]);

            return $this->errorResponse('Tenant context resolution failed');
        }
    }

    /**
     * Resolve tenant context from request
     */
    private function resolveTenantContext(Request $request): ?array
    {
        // Try multiple methods to resolve tenant context
        
        // Method 1: Subdomain-based tenant resolution
        if ($tenantContext = $this->resolveTenantBySubdomain($request)) {
            return $tenantContext;
        }

        // Method 2: API key-based tenant resolution
        if ($tenantContext = $this->resolveTenantByApiKey($request)) {
            return $tenantContext;
        }

        // Method 3: JWT token-based tenant resolution
        if ($tenantContext = $this->resolveTenantByJWT($request)) {
            return $tenantContext;
        }

        // Method 4: Header-based tenant resolution
        if ($tenantContext = $this->resolveTenantByHeader($request)) {
            return $tenantContext;
        }

        return null;
    }

    /**
     * Resolve tenant by subdomain (e.g., tenant1.api.genesis.com)
     */
    private function resolveTenantBySubdomain(Request $request): ?array
    {
        $host = $request->getHost();
        $parts = explode('.', $host);

        if (count($parts) >= 3) {
            $subdomain = $parts[0];
            
            // Cache tenant lookup for performance
            $tenant = Cache::remember("tenant_subdomain_{$subdomain}", 300, function () use ($subdomain) {
                return Tenant::where('slug', $subdomain)
                    ->orWhere('domain', $subdomain)
                    ->active()
                    ->first();
            });

            if ($tenant) {
                // For subdomain-based access, we need additional user authentication
                $user = $this->resolveUserFromRequest($request, $tenant->id);
                
                return [
                    'tenant' => $tenant,
                    'user' => $user,
                    'method' => 'subdomain'
                ];
            }
        }

        return null;
    }

    /**
     * Resolve tenant by API key
     */
    private function resolveTenantByApiKey(Request $request): ?array
    {
        $apiKey = $request->header('X-API-Key') ?: $request->bearerToken();
        
        if (!$apiKey) {
            return null;
        }

        // API keys should be in format: tenant_id.user_id.signature
        $parts = explode('.', $apiKey);
        
        if (count($parts) !== 3) {
            return null;
        }

        [$tenantId, $userId, $signature] = $parts;

        // Verify signature
        $expectedSignature = hash_hmac('sha256', $tenantId . '.' . $userId, config('app.key'));
        
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        // Cache tenant and user lookup
        $cacheKey = "tenant_api_key_{$tenantId}_{$userId}";
        
        return Cache::remember($cacheKey, 300, function () use ($tenantId, $userId) {
            $tenant = Tenant::find($tenantId);
            $user = TenantUser::where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->active()
                ->first();

            if ($tenant && $user) {
                return [
                    'tenant' => $tenant,
                    'user' => $user,
                    'method' => 'api_key'
                ];
            }

            return null;
        });
    }

    /**
     * Resolve tenant by JWT token
     */
    private function resolveTenantByJWT(Request $request): ?array
    {
        $token = $request->bearerToken();
        
        if (!$token) {
            return null;
        }

        try {
            // Decode JWT token (implement your JWT logic here)
            $payload = $this->decodeJWT($token);
            
            if (!isset($payload['tenant_id']) || !isset($payload['user_id'])) {
                return null;
            }

            $cacheKey = "tenant_jwt_{$payload['tenant_id']}_{$payload['user_id']}";
            
            return Cache::remember($cacheKey, 300, function () use ($payload) {
                $tenant = Tenant::find($payload['tenant_id']);
                $user = TenantUser::where('tenant_id', $payload['tenant_id'])
                    ->where('user_id', $payload['user_id'])
                    ->active()
                    ->first();

                if ($tenant && $user) {
                    return [
                        'tenant' => $tenant,
                        'user' => $user,
                        'method' => 'jwt'
                    ];
                }

                return null;
            });

        } catch (\Exception $e) {
            Log::warning('JWT token validation failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Resolve tenant by header
     */
    private function resolveTenantByHeader(Request $request): ?array
    {
        $tenantId = $request->header('X-Tenant-ID');
        $userId = $request->header('X-User-ID');
        
        if (!$tenantId || !$userId) {
            return null;
        }

        $cacheKey = "tenant_header_{$tenantId}_{$userId}";
        
        return Cache::remember($cacheKey, 300, function () use ($tenantId, $userId) {
            $tenant = Tenant::find($tenantId);
            $user = TenantUser::where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->active()
                ->first();

            if ($tenant && $user) {
                return [
                    'tenant' => $tenant,
                    'user' => $user,
                    'method' => 'header'
                ];
            }

            return null;
        });
    }

    /**
     * Validate tenant access and permissions
     */
    private function validateTenantAccess(array $tenantContext, Request $request): array
    {
        $tenant = $tenantContext['tenant'];
        $user = $tenantContext['user'];

        // Check if tenant is active
        if (!$tenant->canPerformAction()) {
            return [
                'valid' => false,
                'message' => 'Tenant is suspended or subscription expired'
            ];
        }

        // Check if user can login
        if (!$user->canLogin()) {
            return [
                'valid' => false,
                'message' => 'User account is suspended or locked'
            ];
        }

        // Check IP restrictions
        $clientIp = $request->ip();
        if (!$tenant->isIpAllowed($clientIp)) {
            Log::warning('IP access denied', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'ip' => $clientIp,
                'allowed_ranges' => $tenant->allowed_ip_ranges
            ]);

            return [
                'valid' => false,
                'message' => 'Access denied from this IP address'
            ];
        }

        // Check if MFA is required and validated
        if ($tenant->enforce_mfa && !$this->validateMFA($request, $user)) {
            return [
                'valid' => false,
                'message' => 'Multi-factor authentication required'
            ];
        }

        // Check route-specific permissions
        if (!$this->validateRoutePermissions($request, $user)) {
            return [
                'valid' => false,
                'message' => 'Insufficient permissions for this operation'
            ];
        }

        return ['valid' => true];
    }

    /**
     * Set tenant scope for database queries
     */
    private function setTenantScope(string $tenantId): void
    {
        // This could be implemented using Laravel's global scopes
        // or a more sophisticated query scoping mechanism
        
        // Store tenant ID in a way that can be accessed by models
        app()->instance('current_tenant_id', $tenantId);
        
        // You might also want to set database connection name based on tenant
        // for complete database isolation (if using separate databases per tenant)
    }

    /**
     * Resolve user from request for subdomain-based access
     */
    private function resolveUserFromRequest(Request $request, string $tenantId): ?TenantUser
    {
        // Try to get user from session, token, etc.
        // This is a simplified implementation
        
        $userId = $request->header('X-User-ID') ?: session('user_id');
        
        if ($userId) {
            return TenantUser::where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->active()
                ->first();
        }

        return null;
    }

    /**
     * Decode JWT token (placeholder implementation)
     */
    private function decodeJWT(string $token): array
    {
        // Implement your JWT decoding logic here
        // This is a placeholder that should be replaced with actual JWT validation
        
        try {
            // Example using Firebase JWT library:
            // return (array) \Firebase\JWT\JWT::decode($token, config('app.jwt_secret'), ['HS256']);
            
            // For now, return empty array
            return [];
        } catch (\Exception $e) {
            throw new \Exception('Invalid JWT token');
        }
    }

    /**
     * Validate MFA if required
     */
    private function validateMFA(Request $request, TenantUser $user): bool
    {
        // Implement MFA validation logic
        // This could check for TOTP codes, SMS codes, etc.
        
        if (!$user->mfa_enabled) {
            return true; // User doesn't have MFA enabled
        }

        $mfaCode = $request->header('X-MFA-Code');
        
        if (!$mfaCode) {
            return false;
        }

        // Validate MFA code (placeholder implementation)
        // In reality, you'd validate against TOTP, SMS, etc.
        return $this->validateTOTPCode($user, $mfaCode);
    }

    /**
     * Validate TOTP code (placeholder)
     */
    private function validateTOTPCode(TenantUser $user, string $code): bool
    {
        // Implement TOTP validation using libraries like RobThree/TwoFactorAuth
        // This is a placeholder
        return true;
    }

    /**
     * Validate route-specific permissions
     */
    private function validateRoutePermissions(Request $request, TenantUser $user): bool
    {
        $route = $request->route();
        
        if (!$route) {
            return true; // No route, allow access
        }

        $routeName = $route->getName();
        $routeAction = $route->getActionName();

        // Define permission mappings for routes
        $permissionMappings = [
            'tenant.users.*' => TenantUser::PERMISSION_MANAGE_USERS,
            'tenant.billing.*' => TenantUser::PERMISSION_MANAGE_BILLING,
            'tenant.analytics.*' => TenantUser::PERMISSION_VIEW_ANALYTICS,
            'orchestration.*' => TenantUser::PERMISSION_MANAGE_ORCHESTRATIONS,
            'agents.*' => TenantUser::PERMISSION_MANAGE_AGENTS,
            'security.*' => TenantUser::PERMISSION_MANAGE_SECURITY,
            'export.*' => TenantUser::PERMISSION_EXPORT_DATA,
            'integrations.*' => TenantUser::PERMISSION_MANAGE_INTEGRATIONS,
        ];

        foreach ($permissionMappings as $pattern => $permission) {
            if (str_starts_with($routeName, str_replace('*', '', $pattern))) {
                return $user->hasPermission($permission);
            }
        }

        // Default: allow access if no specific permission required
        return true;
    }

    /**
     * Log tenant request for audit purposes
     */
    private function logTenantRequest(Request $request, array $tenantContext): void
    {
        Log::info('Tenant request', [
            'tenant_id' => $tenantContext['tenant']->id,
            'tenant_name' => $tenantContext['tenant']->name,
            'user_id' => $tenantContext['user']->id,
            'user_email' => $tenantContext['user']->email,
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'resolution_method' => $tenantContext['method']
        ]);

        // Update user activity
        $tenantContext['user']->updateActivity($request->ip());
    }

    /**
     * Return unauthorized response
     */
    private function unauthorizedResponse(string $message): Response
    {
        return response()->json([
            'error' => 'Unauthorized',
            'message' => $message,
            'code' => 'TENANT_ACCESS_DENIED'
        ], 401);
    }

    /**
     * Return error response
     */
    private function errorResponse(string $message): Response
    {
        return response()->json([
            'error' => 'Internal Server Error',
            'message' => $message,
            'code' => 'TENANT_CONTEXT_ERROR'
        ], 500);
    }
}