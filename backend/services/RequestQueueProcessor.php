<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\Tenant;
use App\Models\SecurityAuditLog;
use Carbon\Carbon;

/**
 * Request Queue Processor Service
 * 
 * Processes queued requests from the enhanced rate limiting system
 * with priority-based scheduling and fair usage enforcement.
 */
class RequestQueueProcessor
{
    private array $config;
    private EnhancedRateLimitService $rateLimitService;
    private bool $running = false;
    
    // Processing states
    const STATE_PENDING = 'pending';
    const STATE_PROCESSING = 'processing';
    const STATE_COMPLETED = 'completed';
    const STATE_FAILED = 'failed';
    const STATE_EXPIRED = 'expired';

    public function __construct(EnhancedRateLimitService $rateLimitService)
    {
        $this->rateLimitService = $rateLimitService;
        $this->config = config('rate_limiting.advanced.request_queuing', [
            'enabled' => true,
            'max_queue_time' => 300,
            'priority_processing' => true,
            'queue_overflow_action' => 'reject',
            'processing_batch_size' => 10,
            'processing_interval' => 1, // seconds
            'max_retry_attempts' => 3,
            'retry_delay_multiplier' => 2,
        ]);
    }

    /**
     * Start the queue processor daemon
     */
    public function start(): void
    {
        if ($this->running) {
            Log::warning('Queue processor is already running');
            return;
        }
        
        $this->running = true;
        Log::info('Starting request queue processor');
        
        // Set up signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
        }
        
        $this->processLoop();
    }

    /**
     * Stop the queue processor
     */
    public function stop(): void
    {
        Log::info('Stopping request queue processor');
        $this->running = false;
    }

    /**
     * Graceful shutdown handler
     */
    public function shutdown(): void
    {
        Log::info('Received shutdown signal, stopping queue processor gracefully');
        $this->stop();
    }

    /**
     * Main processing loop
     */
    private function processLoop(): void
    {
        $lastCleanup = time();
        $processingInterval = $this->config['processing_interval'];
        
        while ($this->running) {
            try {
                // Process handle signals if available
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                
                // Process queued requests
                $this->processQueuedRequests();
                
                // Periodic cleanup (every 5 minutes)
                if (time() - $lastCleanup >= 300) {
                    $this->cleanupExpiredRequests();
                    $lastCleanup = time();
                }
                
                // Sleep between processing cycles
                sleep($processingInterval);
                
            } catch (\Exception $e) {
                Log::error('Queue processor error: ' . $e->getMessage(), [
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Sleep longer on error to avoid tight error loop
                sleep(5);
            }
        }
        
        Log::info('Queue processor stopped');
    }

    /**
     * Process queued requests for all tenants
     */
    private function processQueuedRequests(): void
    {
        $tenants = Tenant::active()->get();
        
        foreach ($tenants as $tenant) {
            $this->processTenantQueue($tenant);
        }
    }

    /**
     * Process queue for a specific tenant
     */
    private function processTenantQueue(Tenant $tenant): void
    {
        $queueKey = "rate_limit:priority_queue:{$tenant->id}";
        $batchSize = $this->config['processing_batch_size'];
        
        // Check if tenant has capacity to process requests
        if (!$this->hasTenantCapacity($tenant)) {
            return;
        }
        
        // Get highest priority requests
        $queuedRequests = Redis::zrange($queueKey, 0, $batchSize - 1, 'WITHSCORES');
        
        if (empty($queuedRequests)) {
            return;
        }
        
        // Process requests in priority order
        $processed = 0;
        for ($i = 0; $i < count($queuedRequests); $i += 2) {
            $requestData = $queuedRequests[$i];
            $priority = $queuedRequests[$i + 1];
            
            if ($this->processQueuedRequest($tenant, $requestData, $priority)) {
                // Remove from queue on successful processing
                Redis::zrem($queueKey, $requestData);
                $processed++;
            }
            
            // Check capacity after each request
            if (!$this->hasTenantCapacity($tenant)) {
                break;
            }
        }
        
        if ($processed > 0) {
            Log::info("Processed {$processed} queued requests for tenant {$tenant->id}");
        }
    }

    /**
     * Process a single queued request
     */
    private function processQueuedRequest(Tenant $tenant, string $requestData, float $priority): bool
    {
        try {
            $request = json_decode($requestData, true);
            if (!$request) {
                Log::error('Invalid request data in queue', ['data' => $requestData]);
                return true; // Remove invalid data from queue
            }
            
            // Check if request has expired
            if ($this->isRequestExpired($request)) {
                $this->logExpiredRequest($request, $tenant);
                return true; // Remove expired request
            }
            
            // Attempt to process the request
            $result = $this->executeQueuedRequest($request, $tenant);
            
            if ($result['success']) {
                $this->logSuccessfulQueuedRequest($request, $tenant, $result);
                return true;
            } else {
                // Handle failed request
                return $this->handleFailedRequest($request, $tenant, $result);
            }
            
        } catch (\Exception $e) {
            Log::error('Error processing queued request', [
                'tenant_id' => $tenant->id,
                'request_data' => $requestData,
                'error' => $e->getMessage()
            ]);
            
            return false; // Keep in queue for retry
        }
    }

    /**
     * Execute a queued request
     */
    private function executeQueuedRequest(array $request, Tenant $tenant): array
    {
        $requestData = $request['request_data'];
        $retryCount = $request['retry_count'] ?? 0;
        
        try {
            // Simulate the original request
            $response = $this->makeInternalRequest(
                $requestData['method'],
                $requestData['path'],
                $requestData['headers'] ?? [],
                $request['payload'] ?? null
            );
            
            return [
                'success' => $response['status'] < 400,
                'status' => $response['status'],
                'response' => $response['body'],
                'retry_count' => $retryCount
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'retry_count' => $retryCount
            ];
        }
    }

    /**
     * Make internal HTTP request
     */
    private function makeInternalRequest(string $method, string $path, array $headers, ?string $payload): array
    {
        $baseUrl = config('app.url');
        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
        
        // Prepare request
        $request = Http::withHeaders($headers);
        
        // Add queue processing header
        $request = $request->withHeaders([
            'X-Queue-Processed' => 'true',
            'X-Queue-Processing-Time' => time()
        ]);
        
        // Execute request based on method
        switch (strtoupper($method)) {
            case 'GET':
                $response = $request->get($url);
                break;
            case 'POST':
                $response = $request->post($url, $payload ? json_decode($payload, true) : []);
                break;
            case 'PUT':
                $response = $request->put($url, $payload ? json_decode($payload, true) : []);
                break;
            case 'DELETE':
                $response = $request->delete($url);
                break;
            case 'PATCH':
                $response = $request->patch($url, $payload ? json_decode($payload, true) : []);
                break;
            default:
                throw new \InvalidArgumentException("Unsupported HTTP method: {$method}");
        }
        
        return [
            'status' => $response->status(),
            'body' => $response->body(),
            'headers' => $response->headers()
        ];
    }

    /**
     * Check if tenant has capacity to process more requests
     */
    private function hasTenantCapacity(Tenant $tenant): bool
    {
        // Check if tenant is within rate limits
        $limits = $this->getTenantCurrentLimits($tenant);
        $currentUsage = $this->getTenantCurrentUsage($tenant);
        
        // Allow processing if under 80% of limit
        $capacityThreshold = 0.8;
        $usageRatio = $currentUsage / max($limits['requests_per_minute'], 1);
        
        return $usageRatio < $capacityThreshold;
    }

    /**
     * Get current rate limits for tenant
     */
    private function getTenantCurrentLimits(Tenant $tenant): array
    {
        $tierConfig = $tenant->getTierLimits();
        
        return [
            'requests_per_minute' => $tierConfig['max_api_calls_per_minute'] ?? 100,
            'burst_size' => ($tierConfig['max_api_calls_per_minute'] ?? 100) * 0.2
        ];
    }

    /**
     * Get current usage for tenant
     */
    private function getTenantCurrentUsage(Tenant $tenant): int
    {
        $key = "rate_limit:org:{$tenant->id}";
        $bucket = Redis::hmget($key, 'tokens', 'last_refill');
        
        if (!$bucket[0]) {
            return 0;
        }
        
        $limits = $this->getTenantCurrentLimits($tenant);
        return max(0, $limits['requests_per_minute'] - (int) $bucket[0]);
    }

    /**
     * Check if request has expired
     */
    private function isRequestExpired(array $request): bool
    {
        $queuedAt = $request['queued_at'] ?? time();
        $maxQueueTime = $this->config['max_queue_time'];
        
        return (time() - $queuedAt) > $maxQueueTime;
    }

    /**
     * Handle failed request processing
     */
    private function handleFailedRequest(array $request, Tenant $tenant, array $result): bool
    {
        $retryCount = $request['retry_count'] ?? 0;
        $maxRetries = $this->config['max_retry_attempts'];
        
        if ($retryCount >= $maxRetries) {
            // Max retries reached, remove from queue
            $this->logFailedQueuedRequest($request, $tenant, $result);
            return true;
        }
        
        // Increment retry count and re-queue with delay
        $request['retry_count'] = $retryCount + 1;
        $request['next_retry_at'] = time() + ($this->config['retry_delay_multiplier'] * ($retryCount + 1));
        
        $this->requeueRequest($request, $tenant);
        return true; // Remove from current position in queue
    }

    /**
     * Re-queue a request for retry
     */
    private function requeueRequest(array $request, Tenant $tenant): void
    {
        $queueKey = "rate_limit:priority_queue:{$tenant->id}";
        $priorityScore = $this->calculatePriorityScore($request['priority'] ?? 'medium');
        
        // Add delay penalty for retries
        $priorityScore += $request['retry_count'] ?? 0;
        
        Redis::zadd($queueKey, $priorityScore, json_encode($request));
        Redis::expire($queueKey, 600); // 10 minutes
    }

    /**
     * Calculate priority score for queue ordering
     */
    private function calculatePriorityScore(string $priority): int
    {
        return match($priority) {
            'critical' => 1,
            'high' => 2,
            'medium' => 3,
            'low' => 4,
            default => 5
        };
    }

    /**
     * Clean up expired requests from all queues
     */
    private function cleanupExpiredRequests(): void
    {
        $tenants = Tenant::active()->get();
        $cleanedUp = 0;
        
        foreach ($tenants as $tenant) {
            $queueKey = "rate_limit:priority_queue:{$tenant->id}";
            $allRequests = Redis::zrange($queueKey, 0, -1);
            
            foreach ($allRequests as $requestData) {
                $request = json_decode($requestData, true);
                if ($request && $this->isRequestExpired($request)) {
                    Redis::zrem($queueKey, $requestData);
                    $cleanedUp++;
                }
            }
        }
        
        if ($cleanedUp > 0) {
            Log::info("Cleaned up {$cleanedUp} expired requests from queues");
        }
    }

    /**
     * Get queue statistics
     */
    public function getQueueStatistics(): array
    {
        $stats = [
            'total_queued' => 0,
            'by_tenant' => [],
            'by_priority' => [
                'critical' => 0,
                'high' => 0,
                'medium' => 0,
                'low' => 0
            ],
            'processing_rate' => $this->getProcessingRate(),
            'average_wait_time' => $this->getAverageWaitTime()
        ];
        
        $tenants = Tenant::active()->get();
        
        foreach ($tenants as $tenant) {
            $queueKey = "rate_limit:priority_queue:{$tenant->id}";
            $queueSize = Redis::zcard($queueKey);
            
            $stats['total_queued'] += $queueSize;
            $stats['by_tenant'][$tenant->id] = [
                'tenant_name' => $tenant->name,
                'queue_size' => $queueSize,
                'tier' => $tenant->tier
            ];
            
            // Count by priority
            $requests = Redis::zrange($queueKey, 0, -1);
            foreach ($requests as $requestData) {
                $request = json_decode($requestData, true);
                if ($request) {
                    $priority = $request['priority'] ?? 'medium';
                    if (isset($stats['by_priority'][$priority])) {
                        $stats['by_priority'][$priority]++;
                    }
                }
            }
        }
        
        return $stats;
    }

    /**
     * Get current processing rate
     */
    private function getProcessingRate(): float
    {
        $key = 'queue_processor:processing_rate';
        $window = 300; // 5 minutes
        $now = time();
        $windowStart = $now - $window;
        
        // Count processed requests in the last 5 minutes
        $processed = Redis::zcount($key, $windowStart, $now);
        
        return $processed / ($window / 60); // requests per minute
    }

    /**
     * Get average wait time for processed requests
     */
    private function getAverageWaitTime(): float
    {
        $key = 'queue_processor:wait_times';
        $window = 300; // 5 minutes
        $now = time();
        $windowStart = $now - $window;
        
        $waitTimes = Redis::zrangebyscore($key, $windowStart, $now);
        
        if (empty($waitTimes)) {
            return 0;
        }
        
        $totalWaitTime = array_sum(array_map('floatval', $waitTimes));
        return $totalWaitTime / count($waitTimes);
    }

    /**
     * Log successful queued request processing
     */
    private function logSuccessfulQueuedRequest(array $request, Tenant $tenant, array $result): void
    {
        $waitTime = time() - ($request['queued_at'] ?? time());
        
        // Record processing metrics
        Redis::zadd('queue_processor:processing_rate', time(), time());
        Redis::zadd('queue_processor:wait_times', time(), $waitTime);
        Redis::expire('queue_processor:processing_rate', 600);
        Redis::expire('queue_processor:wait_times', 600);
        
        SecurityAuditLog::logEvent(
            SecurityAuditLog::EVENT_DATA_ACCESS,
            'Queued request processed successfully',
            SecurityAuditLog::SEVERITY_INFO,
            [
                'tenant_id' => $tenant->id,
                'client_id' => $request['client_id'],
                'path' => $request['request_data']['path'] ?? 'unknown',
                'wait_time_seconds' => $waitTime,
                'retry_count' => $request['retry_count'] ?? 0,
                'priority' => $request['priority'] ?? 'medium',
                'response_status' => $result['status']
            ]
        );
    }

    /**
     * Log failed queued request processing
     */
    private function logFailedQueuedRequest(array $request, Tenant $tenant, array $result): void
    {
        SecurityAuditLog::logEvent(
            SecurityAuditLog::EVENT_SYSTEM_ERROR,
            'Queued request processing failed after max retries',
            SecurityAuditLog::SEVERITY_WARNING,
            [
                'tenant_id' => $tenant->id,
                'client_id' => $request['client_id'],
                'path' => $request['request_data']['path'] ?? 'unknown',
                'retry_count' => $request['retry_count'] ?? 0,
                'priority' => $request['priority'] ?? 'medium',
                'error' => $result['error'] ?? 'unknown',
                'queued_at' => $request['queued_at'] ?? time()
            ]
        );
    }

    /**
     * Log expired request
     */
    private function logExpiredRequest(array $request, Tenant $tenant): void
    {
        $waitTime = time() - ($request['queued_at'] ?? time());
        
        SecurityAuditLog::logEvent(
            SecurityAuditLog::EVENT_SYSTEM_ERROR,
            'Queued request expired before processing',
            SecurityAuditLog::SEVERITY_WARNING,
            [
                'tenant_id' => $tenant->id,
                'client_id' => $request['client_id'],
                'path' => $request['request_data']['path'] ?? 'unknown',
                'wait_time_seconds' => $waitTime,
                'max_queue_time' => $this->config['max_queue_time'],
                'priority' => $request['priority'] ?? 'medium'
            ]
        );
    }
}