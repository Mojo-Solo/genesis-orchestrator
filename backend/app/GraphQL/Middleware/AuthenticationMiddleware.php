<?php

namespace App\GraphQL\Middleware;

use App\Services\AdvancedSecurityService;
use App\Services\AdvancedMonitoringService;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * GraphQL Authentication Middleware
 * 
 * Comprehensive authentication and authorization middleware for GraphQL
 * with advanced security features, rate limiting, and monitoring
 */
class AuthenticationMiddleware
{
    protected AdvancedSecurityService $securityService;
    protected AdvancedMonitoringService $monitoringService;

    public function __construct(
        AdvancedSecurityService $securityService,
        AdvancedMonitoringService $monitoringService
    ) {
        $this->securityService = $securityService;
        $this->monitoringService = $monitoringService;
    }

    /**
     * Handle GraphQL request authentication
     */
    public function __invoke($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo, Closure $next)
    {
        $startTime = microtime(true);
        $request = $context->request();
        $fieldName = $resolveInfo->fieldName;
        $parentType = $resolveInfo->parentType->name;
        
        try {
            // Extract operation details
            $operation = $this->extractOperation($resolveInfo);
            $operationKey = "{$parentType}.{$fieldName}";

            // Apply rate limiting
            $this->applyRateLimit($request, $operationKey);

            // Perform authentication
            $user = $this->authenticateRequest($request, $operationKey);

            // Apply authorization
            $this->authorizeOperation($user, $operation, $args, $context);

            // Apply tenant isolation
            $this->enforceTenantIsolation($user, $operation, $args);

            // Record successful authentication
            $this->monitoringService->recordMetric('graphql.auth.success', 1, [
                'operation' => $operationKey,
                'user_id' => $user?->id,
                'tenant_id' => $user?->tenant_id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);

            // Continue with the GraphQL operation
            return $next($rootValue, $args, $context, $resolveInfo);

        } catch (\Exception $e) {
            // Record authentication failure
            $this->monitoringService->recordMetric('graphql.auth.failed', 1, [
                'operation' => $operationKey ?? 'unknown',
                'error' => $e->getMessage(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);

            // Record security event for failed authentication
            $this->monitoringService->recordSecurityEvent('graphql_auth_failed', [
                'operation' => $operationKey ?? 'unknown',
                'error' => $e->getMessage(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
                'headers' => $this->sanitizeHeaders($request->headers->all()),
            ]);

            throw $e;
        }
    }

    /**
     * Extract operation details from ResolveInfo
     */
    protected function extractOperation(ResolveInfo $resolveInfo): array
    {
        $fieldName = $resolveInfo->fieldName;
        $parentType = $resolveInfo->parentType->name;
        $returnType = $resolveInfo->returnType;
        
        // Determine operation type
        $operationType = match ($parentType) {
            'Query' => 'query',
            'Mutation' => 'mutation',
            'Subscription' => 'subscription',
            default => 'field'
        };

        // Extract field path
        $fieldPath = [];
        $currentInfo = $resolveInfo;
        while ($currentInfo) {
            array_unshift($fieldPath, $currentInfo->fieldName);
            $currentInfo = $currentInfo->parentType ?? null;
            if ($currentInfo && in_array($currentInfo->name, ['Query', 'Mutation', 'Subscription'])) {
                break;
            }
        }

        return [
            'type' => $operationType,
            'field' => $fieldName,
            'parent_type' => $parentType,
            'return_type' => (string) $returnType,
            'field_path' => implode('.', $fieldPath),
            'operation_key' => "{$parentType}.{$fieldName}",
        ];
    }

    /**
     * Apply rate limiting based on operation and user
     */
    protected function applyRateLimit($request, string $operationKey): void
    {
        $user = Auth::user();
        $ipAddress = $request->ip();
        
        // Create rate limit keys
        $keys = [
            "graphql.ip.{$ipAddress}" => $this->getIpRateLimit(),
            "graphql.operation.{$operationKey}.ip.{$ipAddress}" => $this->getOperationRateLimit($operationKey),
        ];

        if ($user) {
            $keys["graphql.user.{$user->id}"] = $this->getUserRateLimit($user);
            $keys["graphql.operation.{$operationKey}.user.{$user->id}"] = $this->getUserOperationRateLimit($user, $operationKey);
        }

        // Check each rate limit
        foreach ($keys as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit['max_attempts'])) {
                $availableAt = RateLimiter::availableAt($key);
                $retryAfter = $availableAt - time();

                $this->monitoringService->recordSecurityEvent('graphql_rate_limit_exceeded', [
                    'rate_limit_key' => $key,
                    'operation' => $operationKey,
                    'user_id' => $user?->id,
                    'ip_address' => $ipAddress,
                    'retry_after_seconds' => $retryAfter,
                ]);

                throw new \Exception("Rate limit exceeded for operation {$operationKey}. Try again in {$retryAfter} seconds.");
            }

            // Hit the rate limiter
            RateLimiter::hit($key, $limit['decay_seconds']);
        }
    }

    /**
     * Authenticate the GraphQL request
     */
    protected function authenticateRequest($request, string $operationKey): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        // Check if operation requires authentication
        if ($this->isPublicOperation($operationKey)) {
            return null;
        }

        // Attempt authentication
        $user = Auth::user();
        
        if (!$user) {
            throw new \Exception('Authentication required for this operation');
        }

        // Validate user account status
        if (!$user->is_active) {
            throw new \Exception('User account is disabled');
        }

        // Validate user session
        $this->validateUserSession($user, $request);

        // Apply additional security checks
        $this->securityService->validateUserRequest($user, $request);

        return $user;
    }

    /**
     * Authorize the GraphQL operation
     */
    protected function authorizeOperation($user, array $operation, array $args, GraphQLContext $context): void
    {
        $operationKey = $operation['operation_key'];
        
        // Check if operation requires authorization
        if ($this->isPublicOperation($operationKey)) {
            return;
        }

        if (!$user) {
            throw new \Exception('User required for authorization');
        }

        // Check user permissions for this operation
        if (!$this->hasOperationPermission($user, $operation, $args)) {
            throw new \Exception("Insufficient permissions for operation: {$operationKey}");
        }

        // Apply field-level authorization
        $this->applyFieldLevelAuthorization($user, $operation, $args, $context);
    }

    /**
     * Enforce tenant isolation
     */
    protected function enforceTenantIsolation($user, array $operation, array $args): void
    {
        if (!$user || !$user->tenant_id) {
            return;
        }

        // Extract tenant-sensitive IDs from arguments
        $tenantSensitiveFields = ['meeting_id', 'user_id', 'tenant_id'];
        
        foreach ($tenantSensitiveFields as $field) {
            if (isset($args[$field])) {
                $this->validateTenantAccess($user, $field, $args[$field]);
            }

            // Check nested input fields
            if (isset($args['input']) && is_array($args['input'])) {
                foreach ($args['input'] as $inputField => $inputValue) {
                    if (in_array($inputField, $tenantSensitiveFields)) {
                        $this->validateTenantAccess($user, $inputField, $inputValue);
                    }
                }
            }
        }
    }

    /**
     * Validate tenant access for specific resource
     */
    protected function validateTenantAccess($user, string $field, $value): void
    {
        if ($field === 'tenant_id' && $value != $user->tenant_id) {
            throw new \Exception('Access denied: Cross-tenant access not allowed');
        }

        if ($field === 'user_id' && $value != $user->id) {
            // Check if user belongs to same tenant
            $targetUser = \App\Models\User::find($value);
            if (!$targetUser || $targetUser->tenant_id != $user->tenant_id) {
                throw new \Exception('Access denied: User not in same tenant');
            }
        }

        if ($field === 'meeting_id') {
            $meeting = \App\Models\Meeting::find($value);
            if (!$meeting || $meeting->tenant_id != $user->tenant_id) {
                throw new \Exception('Access denied: Meeting not in same tenant');
            }
        }
    }

    /**
     * Check if operation is public (no auth required)
     */
    protected function isPublicOperation(string $operationKey): bool
    {
        $publicOperations = [
            'Query.systemHealth',
            'Mutation.login',
            // Add other public operations as needed
        ];

        return in_array($operationKey, $publicOperations);
    }

    /**
     * Check if user has permission for operation
     */
    protected function hasOperationPermission($user, array $operation, array $args): bool
    {
        $operationKey = $operation['operation_key'];
        
        // Admin users have access to all operations
        if ($user->role === 'admin') {
            return true;
        }

        // Define permission matrix
        $permissions = [
            // Query permissions
            'Query.me' => ['admin', 'manager', 'user', 'viewer'],
            'Query.users' => ['admin', 'manager'],
            'Query.meetings' => ['admin', 'manager', 'user'],
            'Query.tenant_analytics' => ['admin', 'manager'],
            
            // Mutation permissions
            'Mutation.createMeeting' => ['admin', 'manager', 'user'],
            'Mutation.updateMeeting' => ['admin', 'manager', 'user'],
            'Mutation.deleteMeeting' => ['admin', 'manager'],
            'Mutation.uploadRecording' => ['admin', 'manager', 'user'],
            
            // Subscription permissions
            'Subscription.meetingUpdated' => ['admin', 'manager', 'user'],
            'Subscription.liveTranscript' => ['admin', 'manager', 'user'],
        ];

        $allowedRoles = $permissions[$operationKey] ?? [];
        
        return in_array($user->role, $allowedRoles);
    }

    /**
     * Apply field-level authorization
     */
    protected function applyFieldLevelAuthorization($user, array $operation, array $args, GraphQLContext $context): void
    {
        $operationKey = $operation['operation_key'];
        
        // Implement field-level restrictions based on user role
        switch ($user->role) {
            case 'viewer':
                $this->applyViewerRestrictions($operationKey, $args);
                break;
            case 'user':
                $this->applyUserRestrictions($operationKey, $args);
                break;
            case 'manager':
                $this->applyManagerRestrictions($operationKey, $args);
                break;
        }
    }

    /**
     * Apply restrictions for viewer role
     */
    protected function applyViewerRestrictions(string $operationKey, array $args): void
    {
        // Viewers can only read, no mutations allowed
        if (str_starts_with($operationKey, 'Mutation.')) {
            throw new \Exception('Viewers cannot perform write operations');
        }

        // Restrict certain sensitive queries
        $restrictedQueries = ['Query.users', 'Query.tenant_analytics'];
        if (in_array($operationKey, $restrictedQueries)) {
            throw new \Exception('Insufficient permissions for this query');
        }
    }

    /**
     * Apply restrictions for user role
     */
    protected function applyUserRestrictions(string $operationKey, array $args): void
    {
        // Users cannot delete meetings
        if ($operationKey === 'Mutation.deleteMeeting') {
            throw new \Exception('Users cannot delete meetings');
        }

        // Users cannot view all users
        if ($operationKey === 'Query.users') {
            throw new \Exception('Users cannot view all users list');
        }
    }

    /**
     * Apply restrictions for manager role
     */
    protected function applyManagerRestrictions(string $operationKey, array $args): void
    {
        // Managers have most permissions, minimal restrictions
        // Add any manager-specific restrictions here
    }

    /**
     * Validate user session
     */
    protected function validateUserSession($user, $request): void
    {
        $sessionId = $request->header('X-Session-ID');
        
        if ($sessionId) {
            $sessionKey = "user_session.{$user->id}.{$sessionId}";
            $sessionData = Cache::get($sessionKey);
            
            if (!$sessionData) {
                throw new \Exception('Invalid or expired session');
            }

            // Update session activity
            $sessionData['last_activity'] = now()->toISOString();
            Cache::put($sessionKey, $sessionData, 3600); // 1 hour
        }
    }

    /**
     * Get IP-based rate limit
     */
    protected function getIpRateLimit(): array
    {
        return [
            'max_attempts' => 1000,
            'decay_seconds' => 3600, // 1 hour
        ];
    }

    /**
     * Get operation-specific rate limit
     */
    protected function getOperationRateLimit(string $operationKey): array
    {
        $limits = [
            'Mutation.login' => ['max_attempts' => 5, 'decay_seconds' => 900], // 5 per 15 mins
            'Mutation.uploadRecording' => ['max_attempts' => 10, 'decay_seconds' => 3600], // 10 per hour
            'Query.searchMeetings' => ['max_attempts' => 100, 'decay_seconds' => 3600], // 100 per hour
        ];

        return $limits[$operationKey] ?? ['max_attempts' => 100, 'decay_seconds' => 3600];
    }

    /**
     * Get user-based rate limit
     */
    protected function getUserRateLimit($user): array
    {
        $tier = $user->tenant->subscription_tier ?? 'free';
        
        $limits = [
            'free' => ['max_attempts' => 100, 'decay_seconds' => 3600],
            'starter' => ['max_attempts' => 500, 'decay_seconds' => 3600],
            'professional' => ['max_attempts' => 2000, 'decay_seconds' => 3600],
            'enterprise' => ['max_attempts' => 10000, 'decay_seconds' => 3600],
        ];

        return $limits[$tier] ?? $limits['free'];
    }

    /**
     * Get user operation-specific rate limit
     */
    protected function getUserOperationRateLimit($user, string $operationKey): array
    {
        $baseLimits = $this->getOperationRateLimit($operationKey);
        $userLimits = $this->getUserRateLimit($user);
        
        // Use the more restrictive limit
        return [
            'max_attempts' => min($baseLimits['max_attempts'], $userLimits['max_attempts']),
            'decay_seconds' => max($baseLimits['decay_seconds'], $userLimits['decay_seconds']),
        ];
    }

    /**
     * Sanitize headers for logging
     */
    protected function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ['authorization', 'cookie', 'x-api-key'];
        
        foreach ($sensitiveHeaders as $header) {
            if (isset($headers[$header])) {
                $headers[$header] = ['[REDACTED]'];
            }
        }

        return $headers;
    }
}