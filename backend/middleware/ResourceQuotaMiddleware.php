<?php

namespace App\Middleware;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TenantResourceUsage;
use App\Services\TenantService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

class ResourceQuotaMiddleware
{
    protected TenantService $tenantService;

    public function __construct(TenantService $tenantService)
    {
        $this->tenantService = $tenantService;
    }

    /**
     * Handle an incoming request and enforce resource quotas
     */
    public function handle(Request $request, Closure $next, string $resourceType = null): BaseResponse
    {
        try {
            // Get tenant context (should be set by TenantIsolationMiddleware)
            $tenant = $this->getTenantFromRequest($request);
            $user = $this->getUserFromRequest($request);

            if (!$tenant || !$user) {
                return $this->errorResponse('Tenant context not found. Ensure TenantIsolationMiddleware runs first.');
            }

            // Determine resource type if not provided
            $resourceType = $resourceType ?: $this->determineResourceType($request);

            // Check tenant status and quotas
            $quotaCheck = $this->checkResourceQuotas($tenant, $resourceType, $request);
            
            if (!$quotaCheck['allowed']) {
                return $this->quotaExceededResponse($quotaCheck['message'], $quotaCheck['quota_info']);
            }

            // Check rate limiting
            $rateLimitCheck = $this->checkRateLimit($tenant, $user, $request);
            
            if (!$rateLimitCheck['allowed']) {
                return $this->rateLimitResponse($rateLimitCheck['message'], $rateLimitCheck['retry_after']);
            }

            // Store pre-request metrics
            $startTime = microtime(true);
            $startMemory = memory_get_usage();

            // Process the request
            $response = $next($request);

            // Calculate resource usage metrics
            $metrics = $this->calculateRequestMetrics($startTime, $startMemory, $response);

            // Record resource usage
            $this->recordResourceUsage($tenant, $resourceType, $metrics, $request);

            // Add quota information to response headers
            $this->addQuotaHeaders($response, $tenant, $resourceType);

            return $response;

        } catch (\Exception $e) {
            Log::error('Resource quota middleware error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_path' => $request->path(),
                'tenant_id' => $request->get('tenant_id')
            ]);

            return $this->errorResponse('Resource quota check failed');
        }
    }

    /**
     * Get tenant from request context
     */
    private function getTenantFromRequest(Request $request): ?Tenant
    {
        $tenantId = $request->get('tenant_id');
        
        if (!$tenantId) {
            return null;
        }

        return Cache::remember("tenant_quota_{$tenantId}", 60, function () use ($tenantId) {
            return Tenant::find($tenantId);
        });
    }

    /**
     * Get user from request context
     */
    private function getUserFromRequest(Request $request): ?TenantUser
    {
        return $request->get('tenant_user');
    }

    /**
     * Determine resource type based on request
     */
    private function determineResourceType(Request $request): string
    {
        $path = $request->path();
        $method = $request->method();

        // Map routes to resource types
        $routeMapping = [
            'api/v1/orchestration' => TenantResourceUsage::RESOURCE_ORCHESTRATION_RUNS,
            'api/v1/agents' => TenantResourceUsage::RESOURCE_AGENT_EXECUTIONS,
            'api/v1/memory' => TenantResourceUsage::RESOURCE_MEMORY_ITEMS,
            'api/v1/router' => TenantResourceUsage::RESOURCE_ROUTER_CALLS,
            'api/v1/upload' => TenantResourceUsage::RESOURCE_STORAGE,
            'api/v1/download' => TenantResourceUsage::RESOURCE_BANDWIDTH,
        ];

        foreach ($routeMapping as $routePrefix => $resourceType) {
            if (str_starts_with($path, $routePrefix)) {
                return $resourceType;
            }
        }

        // Default to API calls for general API usage
        return TenantResourceUsage::RESOURCE_API_CALLS;
    }

    /**
     * Check resource quotas for the tenant
     */
    private function checkResourceQuotas(Tenant $tenant, string $resourceType, Request $request): array
    {
        // Check if tenant can perform actions
        if (!$tenant->canPerformAction()) {
            return [
                'allowed' => false,
                'message' => 'Tenant is suspended or subscription expired',
                'quota_info' => []
            ];
        }

        // Get current usage and limits
        $quotaInfo = $this->getQuotaInfo($tenant, $resourceType);

        // Check specific resource limits
        switch ($resourceType) {
            case TenantResourceUsage::RESOURCE_ORCHESTRATION_RUNS:
                if ($tenant->hasReachedOrchestrationLimit()) {
                    return [
                        'allowed' => false,
                        'message' => 'Monthly orchestration runs limit exceeded',
                        'quota_info' => $quotaInfo
                    ];
                }
                break;

            case TenantResourceUsage::RESOURCE_TOKENS:
                $estimatedTokens = $this->estimateTokenUsage($request);
                if ($tenant->current_tokens_used + $estimatedTokens > $tenant->max_tokens_per_month) {
                    return [
                        'allowed' => false,
                        'message' => 'Monthly token limit would be exceeded',
                        'quota_info' => $quotaInfo
                    ];
                }
                break;

            case TenantResourceUsage::RESOURCE_STORAGE:
                $estimatedStorage = $this->estimateStorageUsage($request);
                if ($tenant->current_storage_gb + $estimatedStorage > $tenant->max_storage_gb) {
                    return [
                        'allowed' => false,
                        'message' => 'Storage limit would be exceeded',
                        'quota_info' => $quotaInfo
                    ];
                }
                break;

            case TenantResourceUsage::RESOURCE_API_CALLS:
                // This is handled by rate limiting, not monthly quotas
                break;

            default:
                // Check general API rate limits
                break;
        }

        // Check for quota warnings (90% threshold)
        $this->checkQuotaWarnings($tenant, $resourceType, $quotaInfo);

        return [
            'allowed' => true,
            'quota_info' => $quotaInfo
        ];
    }

    /**
     * Check rate limiting for API calls
     */
    private function checkRateLimit(Tenant $tenant, TenantUser $user, Request $request): array
    {
        $rateLimitKey = "rate_limit_{$tenant->id}_{$user->id}";
        $maxAttempts = $tenant->max_api_calls_per_minute;
        $decayMinutes = 1;

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($rateLimitKey);
            
            Log::warning('Rate limit exceeded', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'max_attempts' => $maxAttempts,
                'retry_after' => $retryAfter
            ]);

            return [
                'allowed' => false,
                'message' => 'Rate limit exceeded',
                'retry_after' => $retryAfter
            ];
        }

        RateLimiter::hit($rateLimitKey, $decayMinutes * 60);

        return ['allowed' => true];
    }

    /**
     * Calculate request metrics
     */
    private function calculateRequestMetrics(float $startTime, int $startMemory, $response): array
    {
        $endTime = microtime(true);
        $endMemory = memory_get_usage();

        $responseTime = round(($endTime - $startTime) * 1000, 2); // milliseconds
        $memoryUsed = max(0, $endMemory - $startMemory); // bytes
        $isError = $response->getStatusCode() >= 400;

        return [
            'response_time_ms' => $responseTime,
            'memory_used_bytes' => $memoryUsed,
            'error' => $isError,
            'status_code' => $response->getStatusCode(),
            'response_size_bytes' => strlen($response->getContent())
        ];
    }

    /**
     * Record resource usage for billing and analytics
     */
    private function recordResourceUsage(Tenant $tenant, string $resourceType, array $metrics, Request $request): void
    {
        try {
            // Determine usage amount based on resource type
            $usage = $this->calculateUsageAmount($resourceType, $metrics, $request);

            // Record the usage
            $this->tenantService->recordResourceUsage(
                $tenant->id,
                $resourceType,
                $usage,
                $metrics
            );

            // Update user's daily usage counters
            $user = $request->get('tenant_user');
            if ($user && $resourceType === TenantResourceUsage::RESOURCE_API_CALLS) {
                $user->incrementApiCalls();
            }

        } catch (\Exception $e) {
            Log::error('Failed to record resource usage', [
                'tenant_id' => $tenant->id,
                'resource_type' => $resourceType,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Calculate usage amount based on resource type
     */
    private function calculateUsageAmount(string $resourceType, array $metrics, Request $request): int
    {
        switch ($resourceType) {
            case TenantResourceUsage::RESOURCE_STORAGE:
                // Calculate storage usage from request size
                return max(1, ceil($metrics['response_size_bytes'] / (1024 * 1024))); // MB

            case TenantResourceUsage::RESOURCE_BANDWIDTH:
                // Calculate bandwidth from request + response size
                $requestSize = strlen($request->getContent());
                $totalSize = $requestSize + $metrics['response_size_bytes'];
                return max(1, ceil($totalSize / (1024 * 1024))); // MB

            case TenantResourceUsage::RESOURCE_TOKENS:
                // Estimate token usage from request content
                return $this->estimateTokenUsage($request);

            case TenantResourceUsage::RESOURCE_ORCHESTRATION_RUNS:
            case TenantResourceUsage::RESOURCE_AGENT_EXECUTIONS:
            case TenantResourceUsage::RESOURCE_MEMORY_ITEMS:
            case TenantResourceUsage::RESOURCE_ROUTER_CALLS:
            case TenantResourceUsage::RESOURCE_API_CALLS:
            default:
                // These are typically counted as individual operations
                return 1;
        }
    }

    /**
     * Estimate token usage from request
     */
    private function estimateTokenUsage(Request $request): int
    {
        $content = $request->getContent();
        
        if (empty($content)) {
            return 1; // Minimum token usage
        }

        // Rough estimation: ~4 characters per token
        $estimatedTokens = max(1, ceil(strlen($content) / 4));

        // Cap at reasonable maximum for single request
        return min($estimatedTokens, 10000);
    }

    /**
     * Estimate storage usage from request
     */
    private function estimateStorageUsage(Request $request): float
    {
        $content = $request->getContent();
        
        if (empty($content)) {
            return 0;
        }

        // Convert bytes to GB
        return strlen($content) / (1024 * 1024 * 1024);
    }

    /**
     * Get quota information for a tenant and resource type
     */
    private function getQuotaInfo(Tenant $tenant, string $resourceType): array
    {
        $quotas = $this->tenantService->getQuotaUsage($tenant);
        
        return [
            'resource_type' => $resourceType,
            'tenant_tier' => $tenant->tier,
            'quotas' => $quotas,
            'usage_reset_at' => $tenant->usage_reset_at?->toISOString()
        ];
    }

    /**
     * Check for quota warnings and send notifications
     */
    private function checkQuotaWarnings(Tenant $tenant, string $resourceType, array $quotaInfo): void
    {
        $warningThreshold = 0.9; // 90%
        
        foreach ($quotaInfo['quotas'] as $resource => $quota) {
            if ($quota['percentage'] >= ($warningThreshold * 100)) {
                $this->sendQuotaWarning($tenant, $resource, $quota);
            }
        }
    }

    /**
     * Send quota warning notification
     */
    private function sendQuotaWarning(Tenant $tenant, string $resource, array $quota): void
    {
        // Check if warning was already sent recently
        $warningKey = "quota_warning_{$tenant->id}_{$resource}";
        
        if (Cache::has($warningKey)) {
            return; // Warning already sent recently
        }

        Log::warning('Quota warning threshold reached', [
            'tenant_id' => $tenant->id,
            'resource' => $resource,
            'usage_percentage' => $quota['percentage'],
            'current' => $quota['current'],
            'limit' => $quota['limit']
        ]);

        // Set cache to prevent duplicate warnings for 1 hour
        Cache::put($warningKey, true, 3600);

        // Here you would send actual notifications (email, webhook, etc.)
        // This is a placeholder for the notification system
    }

    /**
     * Add quota headers to response
     */
    private function addQuotaHeaders($response, Tenant $tenant, string $resourceType): void
    {
        $quotaInfo = $this->getQuotaInfo($tenant, $resourceType);
        
        // Add general quota headers
        $response->headers->set('X-RateLimit-Limit', $tenant->max_api_calls_per_minute);
        $response->headers->set('X-RateLimit-Remaining', 
            max(0, $tenant->max_api_calls_per_minute - RateLimiter::attempts("rate_limit_{$tenant->id}"))
        );

        // Add resource-specific quota headers
        switch ($resourceType) {
            case TenantResourceUsage::RESOURCE_ORCHESTRATION_RUNS:
                $response->headers->set('X-Quota-Orchestration-Limit', $tenant->max_orchestration_runs_per_month);
                $response->headers->set('X-Quota-Orchestration-Remaining', 
                    max(0, $tenant->max_orchestration_runs_per_month - $tenant->current_orchestration_runs)
                );
                break;

            case TenantResourceUsage::RESOURCE_TOKENS:
                $response->headers->set('X-Quota-Tokens-Limit', $tenant->max_tokens_per_month);
                $response->headers->set('X-Quota-Tokens-Remaining', 
                    max(0, $tenant->max_tokens_per_month - $tenant->current_tokens_used)
                );
                break;

            case TenantResourceUsage::RESOURCE_STORAGE:
                $response->headers->set('X-Quota-Storage-Limit', $tenant->max_storage_gb . 'GB');
                $response->headers->set('X-Quota-Storage-Remaining', 
                    max(0, $tenant->max_storage_gb - $tenant->current_storage_gb) . 'GB'
                );
                break;
        }

        // Add quota reset information
        if ($tenant->usage_reset_at) {
            $response->headers->set('X-Quota-Reset', $tenant->usage_reset_at->timestamp);
        }
    }

    /**
     * Return quota exceeded response
     */
    private function quotaExceededResponse(string $message, array $quotaInfo): Response
    {
        return response()->json([
            'error' => 'Quota Exceeded',
            'message' => $message,
            'code' => 'QUOTA_EXCEEDED',
            'quota_info' => $quotaInfo,
            'upgrade_url' => config('app.url') . '/upgrade'
        ], 429);
    }

    /**
     * Return rate limit response
     */
    private function rateLimitResponse(string $message, int $retryAfter): Response
    {
        return response()->json([
            'error' => 'Rate Limit Exceeded',
            'message' => $message,
            'code' => 'RATE_LIMIT_EXCEEDED',
            'retry_after' => $retryAfter
        ], 429)->header('Retry-After', $retryAfter);
    }

    /**
     * Return error response
     */
    private function errorResponse(string $message): Response
    {
        return response()->json([
            'error' => 'Internal Server Error',
            'message' => $message,
            'code' => 'QUOTA_CHECK_ERROR'
        ], 500);
    }
}