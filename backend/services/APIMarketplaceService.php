<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

class APIMarketplaceService
{
    protected $config;
    protected $rateLimitService;
    protected $auditService;
    protected $circuitBreakerService;

    protected $connectors = [];

    public function __construct(
        EnhancedRateLimitService $rateLimitService,
        SecurityAuditService $auditService,
        CircuitBreakerService $circuitBreakerService
    ) {
        $this->config = Config::get('integrations.api_marketplace');
        $this->rateLimitService = $rateLimitService;
        $this->auditService = $auditService;
        $this->circuitBreakerService = $circuitBreakerService;
        
        $this->initializeConnectors();
    }

    /**
     * Initialize all available connectors
     */
    protected function initializeConnectors(): void
    {
        foreach ($this->config['connectors'] as $name => $config) {
            if ($config['enabled']) {
                $this->connectors[$name] = $this->createConnector($name, $config);
            }
        }
    }

    /**
     * Get available connectors for a tenant
     */
    public function getAvailableConnectors(string $tenantId): array
    {
        $tenant = $this->getTenant($tenantId);
        $available = [];

        foreach ($this->connectors as $name => $connector) {
            if ($this->isConnectorAvailableForTenant($tenant, $name)) {
                $available[$name] = [
                    'name' => $name,
                    'display_name' => $connector->getDisplayName(),
                    'description' => $connector->getDescription(),
                    'category' => $connector->getCategory(),
                    'capabilities' => $connector->getCapabilities(),
                    'auth_type' => $connector->getAuthType(),
                    'status' => $this->getConnectorStatus($tenantId, $name),
                    'configuration_required' => $connector->requiresConfiguration(),
                    'webhook_support' => $connector->supportsWebhooks(),
                ];
            }
        }

        return $available;
    }

    /**
     * Configure a connector for a tenant
     */
    public function configureConnector(string $tenantId, string $connectorName, array $configuration): array
    {
        if (!isset($this->connectors[$connectorName])) {
            throw new Exception("Connector '{$connectorName}' not found");
        }

        $tenant = $this->getTenant($tenantId);
        $connector = $this->connectors[$connectorName];

        // Validate configuration
        $validationResult = $connector->validateConfiguration($configuration);
        if (!$validationResult['valid']) {
            throw new Exception("Invalid configuration: " . implode(', ', $validationResult['errors']));
        }

        // Test connection
        $testResult = $connector->testConnection($configuration);
        if (!$testResult['success']) {
            throw new Exception("Connection test failed: " . $testResult['error']);
        }

        // Store configuration securely
        $configId = $this->storeConnectorConfiguration($tenantId, $connectorName, $configuration);

        // Initialize connector for tenant
        $connector->initialize($tenantId, $configuration);

        $this->auditService->logSecurityEvent([
            'tenant_id' => $tenantId,
            'event_type' => 'connector_configured',
            'connector' => $connectorName,
            'config_id' => $configId,
        ]);

        return [
            'status' => 'configured',
            'config_id' => $configId,
            'capabilities' => $connector->getCapabilities(),
            'test_result' => $testResult,
        ];
    }

    /**
     * Execute API call through connector
     */
    public function executeAPICall(string $tenantId, string $connectorName, string $method, string $endpoint, array $data = [], array $options = []): array
    {
        if (!isset($this->connectors[$connectorName])) {
            throw new Exception("Connector '{$connectorName}' not found");
        }

        $connector = $this->connectors[$connectorName];
        
        // Check rate limits
        $rateLimitKey = "api_marketplace:{$tenantId}:{$connectorName}";
        if (!$this->rateLimitService->attempt($rateLimitKey, $this->getConnectorRateLimit($connectorName))) {
            throw new Exception("Rate limit exceeded for connector '{$connectorName}'");
        }

        // Check circuit breaker
        $circuitKey = "connector:{$connectorName}";
        if ($this->circuitBreakerService->isOpen($circuitKey)) {
            throw new Exception("Circuit breaker is open for connector '{$connectorName}'");
        }

        try {
            // Get tenant configuration
            $configuration = $this->getConnectorConfiguration($tenantId, $connectorName);
            if (!$configuration) {
                throw new Exception("Connector '{$connectorName}' not configured for tenant");
            }

            // Execute API call
            $startTime = microtime(true);
            $response = $connector->executeCall($method, $endpoint, $data, $configuration, $options);
            $duration = microtime(true) - $startTime;

            // Record metrics
            $this->recordAPICallMetrics($tenantId, $connectorName, $method, $endpoint, $duration, true);

            // Log successful call
            $this->auditService->logAPICall([
                'tenant_id' => $tenantId,
                'connector' => $connectorName,
                'method' => $method,
                'endpoint' => $endpoint,
                'duration_ms' => round($duration * 1000),
                'success' => true,
                'status_code' => $response['status_code'] ?? null,
            ]);

            return $response;

        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;
            
            // Record failure metrics
            $this->recordAPICallMetrics($tenantId, $connectorName, $method, $endpoint, $duration, false);
            
            // Record circuit breaker failure
            $this->circuitBreakerService->recordFailure($circuitKey);

            // Log failed call
            $this->auditService->logAPICall([
                'tenant_id' => $tenantId,
                'connector' => $connectorName,
                'method' => $method,
                'endpoint' => $endpoint,
                'duration_ms' => round($duration * 1000),
                'success' => false,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get connector metrics for a tenant
     */
    public function getConnectorMetrics(string $tenantId, string $connectorName, array $timeRange = []): array
    {
        $startDate = $timeRange['start'] ?? Carbon::now()->subDays(7);
        $endDate = $timeRange['end'] ?? Carbon::now();

        $metrics = DB::table('api_call_metrics')
            ->where('tenant_id', $tenantId)
            ->where('connector', $connectorName)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                COUNT(*) as total_calls,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_calls,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_calls,
                AVG(duration_ms) as avg_duration_ms,
                MAX(duration_ms) as max_duration_ms,
                MIN(duration_ms) as min_duration_ms
            ')
            ->first();

        $hourlyStats = DB::table('api_call_metrics')
            ->where('tenant_id', $tenantId)
            ->where('connector', $connectorName)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                DATE_FORMAT(created_at, "%Y-%m-%d %H:00:00") as hour,
                COUNT(*) as calls,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful,
                AVG(duration_ms) as avg_duration
            ')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        return [
            'summary' => [
                'total_calls' => $metrics->total_calls ?? 0,
                'successful_calls' => $metrics->successful_calls ?? 0,
                'failed_calls' => $metrics->failed_calls ?? 0,
                'success_rate' => $metrics->total_calls > 0 ? ($metrics->successful_calls / $metrics->total_calls) : 0,
                'avg_duration_ms' => round($metrics->avg_duration_ms ?? 0, 2),
                'max_duration_ms' => $metrics->max_duration_ms ?? 0,
                'min_duration_ms' => $metrics->min_duration_ms ?? 0,
            ],
            'hourly_stats' => $hourlyStats->toArray(),
            'rate_limit_status' => $this->getRateLimitStatus($tenantId, $connectorName),
            'circuit_breaker_status' => $this->circuitBreakerService->getStatus("connector:{$connectorName}"),
        ];
    }

    /**
     * Handle webhook from external service
     */
    public function handleWebhook(string $connectorName, array $headers, string $payload, string $signature = null): array
    {
        if (!isset($this->connectors[$connectorName])) {
            throw new Exception("Connector '{$connectorName}' not found");
        }

        $connector = $this->connectors[$connectorName];

        if (!$connector->supportsWebhooks()) {
            throw new Exception("Connector '{$connectorName}' does not support webhooks");
        }

        // Verify webhook signature
        if ($signature && !$connector->verifyWebhookSignature($payload, $signature, $headers)) {
            throw new Exception("Invalid webhook signature");
        }

        // Parse webhook payload
        $webhookData = $connector->parseWebhookPayload($payload, $headers);
        
        // Get affected tenants
        $tenants = $this->getTenantsForWebhook($connectorName, $webhookData);

        $results = [];
        foreach ($tenants as $tenantId) {
            try {
                // Process webhook for each tenant
                $result = $this->processWebhookForTenant($tenantId, $connectorName, $webhookData);
                $results[$tenantId] = $result;

                $this->auditService->logWebhookEvent([
                    'tenant_id' => $tenantId,
                    'connector' => $connectorName,
                    'event_type' => $webhookData['event_type'] ?? 'unknown',
                    'success' => true,
                ]);

            } catch (Exception $e) {
                $results[$tenantId] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];

                $this->auditService->logWebhookEvent([
                    'tenant_id' => $tenantId,
                    'connector' => $connectorName,
                    'event_type' => $webhookData['event_type'] ?? 'unknown',
                    'success' => false,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'processed_tenants' => count($tenants),
            'results' => $results,
        ];
    }

    /**
     * Get webhook configuration for a connector
     */
    public function getWebhookConfiguration(string $tenantId, string $connectorName): array
    {
        if (!isset($this->connectors[$connectorName])) {
            throw new Exception("Connector '{$connectorName}' not found");
        }

        $connector = $this->connectors[$connectorName];

        if (!$connector->supportsWebhooks()) {
            return ['supported' => false];
        }

        $webhookUrl = url("/api/v1/integrations/webhooks/{$connectorName}");
        $configuration = $connector->getWebhookConfiguration($tenantId, $webhookUrl);

        return [
            'supported' => true,
            'webhook_url' => $webhookUrl,
            'configuration' => $configuration,
            'events' => $connector->getSupportedWebhookEvents(),
        ];
    }

    /**
     * Bulk operations for connectors
     */
    public function executeBulkOperation(string $tenantId, string $connectorName, string $operation, array $items, array $options = []): array
    {
        if (!isset($this->connectors[$connectorName])) {
            throw new Exception("Connector '{$connectorName}' not found");
        }

        $connector = $this->connectors[$connectorName];

        if (!$connector->supportsBulkOperations()) {
            // Fall back to individual operations
            return $this->executeBulkOperationFallback($tenantId, $connectorName, $operation, $items, $options);
        }

        // Check rate limits for bulk operation
        $rateLimitKey = "api_marketplace_bulk:{$tenantId}:{$connectorName}";
        if (!$this->rateLimitService->attempt($rateLimitKey, $this->getConnectorRateLimit($connectorName) * 10)) {
            throw new Exception("Rate limit exceeded for bulk operation");
        }

        try {
            $configuration = $this->getConnectorConfiguration($tenantId, $connectorName);
            $startTime = microtime(true);
            
            $result = $connector->executeBulkOperation($operation, $items, $configuration, $options);
            
            $duration = microtime(true) - $startTime;

            $this->auditService->logBulkOperation([
                'tenant_id' => $tenantId,
                'connector' => $connectorName,
                'operation' => $operation,
                'items_count' => count($items),
                'duration_ms' => round($duration * 1000),
                'success' => true,
            ]);

            return $result;

        } catch (Exception $e) {
            $this->auditService->logBulkOperation([
                'tenant_id' => $tenantId,
                'connector' => $connectorName,
                'operation' => $operation,
                'items_count' => count($items),
                'success' => false,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Disconnect a connector for a tenant
     */
    public function disconnectConnector(string $tenantId, string $connectorName): array
    {
        // Remove configuration
        $this->removeConnectorConfiguration($tenantId, $connectorName);

        // Clean up any stored credentials
        if (isset($this->connectors[$connectorName])) {
            $this->connectors[$connectorName]->cleanup($tenantId);
        }

        $this->auditService->logSecurityEvent([
            'tenant_id' => $tenantId,
            'event_type' => 'connector_disconnected',
            'connector' => $connectorName,
        ]);

        return ['status' => 'disconnected'];
    }

    /**
     * Helper methods
     */
    protected function createConnector(string $name, array $config): APIConnectorInterface
    {
        $className = 'App\\Connectors\\' . ucfirst(str_replace('_', '', $name)) . 'Connector';
        
        if (!class_exists($className)) {
            throw new Exception("Connector class '{$className}' not found");
        }

        return new $className($config, $this->rateLimitService);
    }

    protected function isConnectorAvailableForTenant(Tenant $tenant, string $connectorName): bool
    {
        // Check tier restrictions
        $tierRestrictions = [
            'free' => ['slack'],
            'starter' => ['slack', 'github', 'gitlab'],
            'professional' => ['slack', 'github', 'gitlab', 'jira', 'zendesk'],
            'enterprise' => null, // All connectors available
        ];

        $allowedConnectors = $tierRestrictions[$tenant->tier] ?? [];
        
        return $allowedConnectors === null || in_array($connectorName, $allowedConnectors);
    }

    protected function getConnectorStatus(string $tenantId, string $connectorName): string
    {
        $configuration = $this->getConnectorConfiguration($tenantId, $connectorName);
        
        if (!$configuration) {
            return 'not_configured';
        }

        // Test connection
        try {
            $connector = $this->connectors[$connectorName];
            $testResult = $connector->testConnection($configuration);
            return $testResult['success'] ? 'connected' : 'error';
        } catch (Exception $e) {
            return 'error';
        }
    }

    protected function getConnectorRateLimit(string $connectorName): array
    {
        $config = $this->config['connectors'][$connectorName] ?? [];
        return $config['rate_limit'] ?? ['requests_per_minute' => 60, 'burst_size' => 10];
    }

    protected function storeConnectorConfiguration(string $tenantId, string $connectorName, array $configuration): string
    {
        $configId = bin2hex(random_bytes(16));
        
        // Encrypt sensitive data
        $encryptedConfig = encrypt($configuration);
        
        DB::table('tenant_connector_configurations')->insert([
            'id' => $configId,
            'tenant_id' => $tenantId,
            'connector_name' => $connectorName,
            'configuration' => $encryptedConfig,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return $configId;
    }

    protected function getConnectorConfiguration(string $tenantId, string $connectorName): ?array
    {
        $record = DB::table('tenant_connector_configurations')
            ->where('tenant_id', $tenantId)
            ->where('connector_name', $connectorName)
            ->first();

        if (!$record) {
            return null;
        }

        return decrypt($record->configuration);
    }

    protected function removeConnectorConfiguration(string $tenantId, string $connectorName): void
    {
        DB::table('tenant_connector_configurations')
            ->where('tenant_id', $tenantId)
            ->where('connector_name', $connectorName)
            ->delete();
    }

    protected function recordAPICallMetrics(string $tenantId, string $connectorName, string $method, string $endpoint, float $duration, bool $success): void
    {
        DB::table('api_call_metrics')->insert([
            'tenant_id' => $tenantId,
            'connector' => $connectorName,
            'method' => $method,
            'endpoint' => $endpoint,
            'duration_ms' => round($duration * 1000),
            'success' => $success,
            'created_at' => Carbon::now(),
        ]);
    }

    protected function getRateLimitStatus(string $tenantId, string $connectorName): array
    {
        $rateLimitKey = "api_marketplace:{$tenantId}:{$connectorName}";
        return $this->rateLimitService->getStatus($rateLimitKey);
    }

    protected function getTenantsForWebhook(string $connectorName, array $webhookData): array
    {
        // Get all tenants that have this connector configured
        return DB::table('tenant_connector_configurations')
            ->where('connector_name', $connectorName)
            ->pluck('tenant_id')
            ->toArray();
    }

    protected function processWebhookForTenant(string $tenantId, string $connectorName, array $webhookData): array
    {
        $connector = $this->connectors[$connectorName];
        $configuration = $this->getConnectorConfiguration($tenantId, $connectorName);

        return $connector->processWebhook($tenantId, $webhookData, $configuration);
    }

    protected function executeBulkOperationFallback(string $tenantId, string $connectorName, string $operation, array $items, array $options): array
    {
        $results = [];
        $successful = 0;
        $failed = 0;

        foreach ($items as $index => $item) {
            try {
                $result = $this->executeAPICall($tenantId, $connectorName, 'POST', $operation, $item, $options);
                $results[$index] = ['success' => true, 'data' => $result];
                $successful++;
            } catch (Exception $e) {
                $results[$index] = ['success' => false, 'error' => $e->getMessage()];
                $failed++;
            }
        }

        return [
            'total' => count($items),
            'successful' => $successful,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    protected function getTenant(string $tenantId): Tenant
    {
        return Tenant::findOrFail($tenantId);
    }
}