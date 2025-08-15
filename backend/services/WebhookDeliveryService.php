<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use App\Jobs\DeliverWebhookJob;
use App\Jobs\ProcessDeadLetterWebhookJob;
use Carbon\Carbon;
use Exception;

class WebhookDeliveryService
{
    protected $config;
    protected $rateLimitService;
    protected $auditService;

    public function __construct(
        EnhancedRateLimitService $rateLimitService,
        SecurityAuditService $auditService
    ) {
        $this->config = Config::get('integrations.webhook_delivery');
        $this->rateLimitService = $rateLimitService;
        $this->auditService = $auditService;
    }

    /**
     * Register a webhook endpoint for a tenant
     */
    public function registerWebhook(string $tenantId, array $webhookConfig): string
    {
        $webhookId = $this->generateWebhookId();
        
        $webhook = [
            'id' => $webhookId,
            'tenant_id' => $tenantId,
            'url' => $webhookConfig['url'],
            'events' => $webhookConfig['events'] ?? [],
            'secret' => $webhookConfig['secret'] ?? $this->generateSecret(),
            'active' => $webhookConfig['active'] ?? true,
            'retry_config' => $webhookConfig['retry_config'] ?? $this->getDefaultRetryConfig(),
            'headers' => $webhookConfig['headers'] ?? [],
            'timeout' => $webhookConfig['timeout'] ?? $this->config['timeout'],
            'verify_ssl' => $webhookConfig['verify_ssl'] ?? $this->config['verify_ssl'],
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];

        DB::table('webhook_endpoints')->insert($webhook);

        $this->auditService->logSecurityEvent([
            'tenant_id' => $tenantId,
            'event_type' => 'webhook_registered',
            'webhook_id' => $webhookId,
            'url' => $webhookConfig['url'],
            'events' => $webhookConfig['events'] ?? [],
        ]);

        Log::info('Webhook endpoint registered', [
            'tenant_id' => $tenantId,
            'webhook_id' => $webhookId,
            'url' => $webhookConfig['url'],
        ]);

        return $webhookId;
    }

    /**
     * Dispatch webhook event to registered endpoints
     */
    public function dispatchEvent(string $eventType, array $eventData, array $options = []): array
    {
        if (!$this->isEventEnabled($eventType)) {
            return [
                'dispatched' => false,
                'reason' => 'Event type not enabled',
            ];
        }

        // Get all active webhooks subscribed to this event
        $webhooks = $this->getSubscribedWebhooks($eventType, $options['tenant_id'] ?? null);
        
        if (empty($webhooks)) {
            return [
                'dispatched' => false,
                'reason' => 'No webhooks subscribed to event',
                'event_type' => $eventType,
            ];
        }

        $deliveryId = $this->generateDeliveryId();
        $deliveryResults = [];

        foreach ($webhooks as $webhook) {
            try {
                // Check rate limits for this endpoint
                $rateLimitKey = "webhook_delivery:{$webhook->id}";
                if (!$this->rateLimitService->attempt($rateLimitKey, ['requests_per_minute' => $this->config['rate_limit_per_endpoint']])) {
                    $deliveryResults[$webhook->id] = [
                        'status' => 'rate_limited',
                        'message' => 'Rate limit exceeded for webhook endpoint',
                    ];
                    continue;
                }

                // Prepare payload
                $payload = $this->preparePayload($eventType, $eventData, $webhook, $deliveryId);
                
                // Queue webhook delivery
                $jobOptions = [
                    'priority' => $this->getEventPriority($eventType),
                    'attempts' => $webhook->retry_config['max_retries'] ?? $this->config['max_retries'],
                    'delay' => $options['delay'] ?? 0,
                ];

                DeliverWebhookJob::dispatch($webhook, $payload, $deliveryId)
                    ->onQueue($this->getQueueForPriority($jobOptions['priority']))
                    ->delay($jobOptions['delay']);

                $deliveryResults[$webhook->id] = [
                    'status' => 'queued',
                    'delivery_id' => $deliveryId,
                    'queue_priority' => $jobOptions['priority'],
                ];

                // Log webhook dispatch
                $this->logWebhookDispatch($webhook, $eventType, $deliveryId, 'queued');

            } catch (Exception $e) {
                $deliveryResults[$webhook->id] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];

                Log::error('Failed to queue webhook delivery', [
                    'webhook_id' => $webhook->id,
                    'event_type' => $eventType,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'dispatched' => true,
            'delivery_id' => $deliveryId,
            'event_type' => $eventType,
            'webhooks_count' => count($webhooks),
            'results' => $deliveryResults,
        ];
    }

    /**
     * Deliver webhook payload to endpoint
     */
    public function deliverWebhook($webhook, array $payload, string $deliveryId, int $attempt = 1): array
    {
        $startTime = microtime(true);
        
        try {
            // Prepare HTTP client
            $httpClient = Http::timeout($webhook->timeout ?? $this->config['timeout']);
            
            if (!$webhook->verify_ssl) {
                $httpClient = $httpClient->withoutVerifying();
            }

            // Add custom headers
            $headers = array_merge([
                'Content-Type' => 'application/json',
                'User-Agent' => $this->config['security']['user_agent'],
                $this->config['security']['timestamp_header'] => time(),
            ], $webhook->headers ?? []);

            // Sign payload if enabled
            if ($this->config['security']['sign_payloads']) {
                $signature = $this->signPayload($payload, $webhook->secret);
                $headers[$this->config['security']['signature_header']] = $signature;
            }

            // Make the HTTP request
            $response = $httpClient->withHeaders($headers)
                ->post($webhook->url, $payload);

            $duration = microtime(true) - $startTime;
            $statusCode = $response->status();
            $successful = $response->successful();

            // Log delivery attempt
            $this->logWebhookDelivery([
                'webhook_id' => $webhook->id,
                'delivery_id' => $deliveryId,
                'attempt' => $attempt,
                'status_code' => $statusCode,
                'duration_ms' => round($duration * 1000),
                'success' => $successful,
                'response_size' => strlen($response->body()),
                'error' => $successful ? null : $response->body(),
            ]);

            if ($successful) {
                return [
                    'success' => true,
                    'status_code' => $statusCode,
                    'duration_ms' => round($duration * 1000),
                    'attempt' => $attempt,
                    'response' => $response->body(),
                ];
            } else {
                throw new Exception("HTTP {$statusCode}: " . $response->body());
            }

        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;
            
            $this->logWebhookDelivery([
                'webhook_id' => $webhook->id,
                'delivery_id' => $deliveryId,
                'attempt' => $attempt,
                'duration_ms' => round($duration * 1000),
                'success' => false,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'duration_ms' => round($duration * 1000),
                'attempt' => $attempt,
            ];
        }
    }

    /**
     * Handle webhook delivery failure and retry logic
     */
    public function handleDeliveryFailure($webhook, array $payload, string $deliveryId, int $attempt, string $error): void
    {
        $retryConfig = $webhook->retry_config ?? $this->getDefaultRetryConfig();
        $maxRetries = $retryConfig['max_retries'] ?? $this->config['max_retries'];

        if ($attempt < $maxRetries) {
            // Calculate delay for next retry
            $delay = $this->calculateRetryDelay($attempt, $retryConfig['retry_policy'] ?? 'exponential_backoff');
            
            // Queue retry
            DeliverWebhookJob::dispatch($webhook, $payload, $deliveryId)
                ->delay($delay)
                ->onQueue('webhook_retries');

            Log::info('Webhook delivery retry scheduled', [
                'webhook_id' => $webhook->id,
                'delivery_id' => $deliveryId,
                'attempt' => $attempt + 1,
                'delay_seconds' => $delay,
            ]);

        } else {
            // Max retries reached, move to dead letter queue
            if ($this->config['dead_letter_queue']) {
                ProcessDeadLetterWebhookJob::dispatch($webhook, $payload, $deliveryId, $error)
                    ->onQueue('webhook_dead_letter');

                Log::warning('Webhook moved to dead letter queue', [
                    'webhook_id' => $webhook->id,
                    'delivery_id' => $deliveryId,
                    'final_attempt' => $attempt,
                    'error' => $error,
                ]);
            }

            // Update webhook statistics
            $this->updateWebhookStats($webhook->id, 'failed');
            
            // Check if webhook should be disabled
            $this->checkWebhookHealth($webhook);
        }
    }

    /**
     * Get webhook delivery statistics
     */
    public function getWebhookStatistics(string $webhookId, array $timeRange = []): array
    {
        $startDate = $timeRange['start'] ?? Carbon::now()->subDays(7);
        $endDate = $timeRange['end'] ?? Carbon::now();

        $stats = DB::table('webhook_deliveries')
            ->where('webhook_id', $webhookId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                COUNT(*) as total_deliveries,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_deliveries,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_deliveries,
                AVG(duration_ms) as avg_duration_ms,
                MAX(duration_ms) as max_duration_ms,
                AVG(attempt) as avg_attempts
            ')
            ->first();

        $hourlyStats = DB::table('webhook_deliveries')
            ->where('webhook_id', $webhookId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                DATE_FORMAT(created_at, "%Y-%m-%d %H:00:00") as hour,
                COUNT(*) as deliveries,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful,
                AVG(duration_ms) as avg_duration
            ')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        $responseCodeStats = DB::table('webhook_deliveries')
            ->where('webhook_id', $webhookId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('status_code, COUNT(*) as count')
            ->groupBy('status_code')
            ->get();

        return [
            'webhook_id' => $webhookId,
            'time_range' => ['start' => $startDate, 'end' => $endDate],
            'summary' => [
                'total_deliveries' => $stats->total_deliveries ?? 0,
                'successful_deliveries' => $stats->successful_deliveries ?? 0,
                'failed_deliveries' => $stats->failed_deliveries ?? 0,
                'success_rate' => $stats->total_deliveries > 0 ? ($stats->successful_deliveries / $stats->total_deliveries) : 0,
                'avg_duration_ms' => round($stats->avg_duration_ms ?? 0, 2),
                'max_duration_ms' => $stats->max_duration_ms ?? 0,
                'avg_attempts' => round($stats->avg_attempts ?? 0, 2),
            ],
            'hourly_stats' => $hourlyStats->toArray(),
            'response_codes' => $responseCodeStats->keyBy('status_code')->toArray(),
        ];
    }

    /**
     * Update webhook configuration
     */
    public function updateWebhook(string $webhookId, array $updates): bool
    {
        $allowedUpdates = ['url', 'events', 'active', 'headers', 'timeout', 'verify_ssl', 'retry_config'];
        $filteredUpdates = array_intersect_key($updates, array_flip($allowedUpdates));
        $filteredUpdates['updated_at'] = Carbon::now();

        $updated = DB::table('webhook_endpoints')
            ->where('id', $webhookId)
            ->update($filteredUpdates);

        if ($updated) {
            $webhook = $this->getWebhook($webhookId);
            
            $this->auditService->logSecurityEvent([
                'tenant_id' => $webhook->tenant_id,
                'event_type' => 'webhook_updated',
                'webhook_id' => $webhookId,
                'updates' => array_keys($filteredUpdates),
            ]);
        }

        return $updated > 0;
    }

    /**
     * Deactivate webhook
     */
    public function deactivateWebhook(string $webhookId): bool
    {
        return $this->updateWebhook($webhookId, ['active' => false]);
    }

    /**
     * Delete webhook
     */
    public function deleteWebhook(string $webhookId): bool
    {
        $webhook = $this->getWebhook($webhookId);
        
        if (!$webhook) {
            return false;
        }

        // Delete webhook and all related data
        DB::transaction(function () use ($webhookId) {
            DB::table('webhook_deliveries')->where('webhook_id', $webhookId)->delete();
            DB::table('webhook_endpoints')->where('id', $webhookId)->delete();
        });

        $this->auditService->logSecurityEvent([
            'tenant_id' => $webhook->tenant_id,
            'event_type' => 'webhook_deleted',
            'webhook_id' => $webhookId,
        ]);

        return true;
    }

    /**
     * Get webhooks for a tenant
     */
    public function getTenantWebhooks(string $tenantId, bool $activeOnly = false): array
    {
        $query = DB::table('webhook_endpoints')->where('tenant_id', $tenantId);
        
        if ($activeOnly) {
            $query->where('active', true);
        }

        return $query->get()->toArray();
    }

    /**
     * Test webhook endpoint
     */
    public function testWebhook(string $webhookId): array
    {
        $webhook = $this->getWebhook($webhookId);
        
        if (!$webhook) {
            throw new Exception('Webhook not found');
        }

        $testPayload = [
            'event_type' => 'webhook_test',
            'webhook_id' => $webhookId,
            'timestamp' => time(),
            'test_data' => [
                'message' => 'This is a test webhook delivery from GENESIS Orchestrator',
                'test_id' => bin2hex(random_bytes(8)),
            ],
        ];

        $deliveryId = $this->generateDeliveryId();
        
        return $this->deliverWebhook($webhook, $testPayload, $deliveryId);
    }

    /**
     * Helper methods
     */
    protected function isEventEnabled(string $eventType): bool
    {
        $eventConfig = $this->config['events'][$eventType] ?? null;
        return $eventConfig && ($eventConfig['enabled'] ?? false);
    }

    protected function getSubscribedWebhooks(string $eventType, ?string $tenantId = null): array
    {
        $query = DB::table('webhook_endpoints')
            ->where('active', true)
            ->whereJsonContains('events', $eventType);

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->get()->toArray();
    }

    protected function preparePayload(string $eventType, array $eventData, $webhook, string $deliveryId): array
    {
        $template = $this->config['events'][$eventType]['payload_template'] ?? 'default';
        
        $payload = [
            'event_type' => $eventType,
            'delivery_id' => $deliveryId,
            'timestamp' => time(),
            'tenant_id' => $webhook->tenant_id,
            'data' => $eventData,
        ];

        // Apply template-specific formatting
        return $this->applyPayloadTemplate($payload, $template);
    }

    protected function applyPayloadTemplate(array $payload, string $template): array
    {
        switch ($template) {
            case 'orchestration_started':
            case 'orchestration_completed':
                return [
                    'event' => $payload['event_type'],
                    'delivery_id' => $payload['delivery_id'],
                    'timestamp' => $payload['timestamp'],
                    'orchestration' => $payload['data'],
                ];

            case 'security_incident':
                return [
                    'event' => 'security.incident',
                    'severity' => $payload['data']['severity'] ?? 'medium',
                    'incident_id' => $payload['data']['incident_id'] ?? null,
                    'timestamp' => $payload['timestamp'],
                    'details' => $payload['data'],
                ];

            default:
                return $payload;
        }
    }

    protected function getEventPriority(string $eventType): string
    {
        $eventConfig = $this->config['events'][$eventType] ?? [];
        return $eventConfig['priority'] ?? 'normal';
    }

    protected function getQueueForPriority(string $priority): string
    {
        $queueMap = [
            'critical' => 'webhook_critical',
            'high' => 'webhook_high',
            'normal' => 'webhook_normal',
            'low' => 'webhook_low',
        ];

        return $queueMap[$priority] ?? 'webhook_normal';
    }

    protected function signPayload(array $payload, string $secret): string
    {
        $algorithm = $this->config['security']['signature_algorithm'];
        $data = json_encode($payload, JSON_UNESCAPED_SLASHES);
        
        return $algorithm . '=' . hash_hmac($algorithm, $data, $secret);
    }

    protected function calculateRetryDelay(int $attempt, string $retryPolicy): int
    {
        $delays = $this->config['retry_delays'];
        
        switch ($retryPolicy) {
            case 'exponential_backoff':
                return $delays[$attempt - 1] ?? end($delays);
            
            case 'linear_backoff':
                return 60 * $attempt; // 1 minute, 2 minutes, 3 minutes, etc.
            
            case 'immediate':
                return 0;
            
            default:
                return $delays[$attempt - 1] ?? 300; // Default to 5 minutes
        }
    }

    protected function getDefaultRetryConfig(): array
    {
        return [
            'max_retries' => $this->config['max_retries'],
            'retry_policy' => 'exponential_backoff',
        ];
    }

    protected function logWebhookDispatch($webhook, string $eventType, string $deliveryId, string $status): void
    {
        DB::table('webhook_dispatch_log')->insert([
            'webhook_id' => $webhook->id,
            'tenant_id' => $webhook->tenant_id,
            'event_type' => $eventType,
            'delivery_id' => $deliveryId,
            'status' => $status,
            'created_at' => Carbon::now(),
        ]);
    }

    protected function logWebhookDelivery(array $deliveryData): void
    {
        $deliveryData['created_at'] = Carbon::now();
        DB::table('webhook_deliveries')->insert($deliveryData);
    }

    protected function updateWebhookStats(string $webhookId, string $result): void
    {
        $stats = Cache::get("webhook_stats_{$webhookId}", [
            'total_deliveries' => 0,
            'successful_deliveries' => 0,
            'failed_deliveries' => 0,
        ]);

        $stats['total_deliveries']++;
        if ($result === 'success') {
            $stats['successful_deliveries']++;
        } else {
            $stats['failed_deliveries']++;
        }

        Cache::put("webhook_stats_{$webhookId}", $stats, 3600);
    }

    protected function checkWebhookHealth($webhook): void
    {
        $stats = Cache::get("webhook_stats_{$webhook->id}");
        
        if (!$stats || $stats['total_deliveries'] < 10) {
            return; // Not enough data
        }

        $failureRate = $stats['failed_deliveries'] / $stats['total_deliveries'];
        
        if ($failureRate > 0.9) { // 90% failure rate
            $this->deactivateWebhook($webhook->id);
            
            Log::warning('Webhook automatically disabled due to high failure rate', [
                'webhook_id' => $webhook->id,
                'failure_rate' => $failureRate,
            ]);
        }
    }

    protected function getWebhook(string $webhookId)
    {
        return DB::table('webhook_endpoints')->where('id', $webhookId)->first();
    }

    protected function generateWebhookId(): string
    {
        return 'wh_' . bin2hex(random_bytes(16));
    }

    protected function generateDeliveryId(): string
    {
        return 'wd_' . bin2hex(random_bytes(16));
    }

    protected function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}