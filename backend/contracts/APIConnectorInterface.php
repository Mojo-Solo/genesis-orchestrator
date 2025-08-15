<?php

namespace App\Contracts;

interface APIConnectorInterface
{
    /**
     * Get connector display name
     */
    public function getDisplayName(): string;

    /**
     * Get connector description
     */
    public function getDescription(): string;

    /**
     * Get connector category
     */
    public function getCategory(): string;

    /**
     * Get connector capabilities
     */
    public function getCapabilities(): array;

    /**
     * Get authentication type required
     */
    public function getAuthType(): string;

    /**
     * Check if connector requires configuration
     */
    public function requiresConfiguration(): bool;

    /**
     * Check if connector supports webhooks
     */
    public function supportsWebhooks(): bool;

    /**
     * Check if connector supports bulk operations
     */
    public function supportsBulkOperations(): bool;

    /**
     * Validate connector configuration
     */
    public function validateConfiguration(array $configuration): array;

    /**
     * Test connection with given configuration
     */
    public function testConnection(array $configuration): array;

    /**
     * Initialize connector for tenant
     */
    public function initialize(string $tenantId, array $configuration): void;

    /**
     * Execute API call
     */
    public function executeCall(string $method, string $endpoint, array $data, array $configuration, array $options = []): array;

    /**
     * Get webhook configuration
     */
    public function getWebhookConfiguration(string $tenantId, string $webhookUrl): array;

    /**
     * Get supported webhook events
     */
    public function getSupportedWebhookEvents(): array;

    /**
     * Verify webhook signature
     */
    public function verifyWebhookSignature(string $payload, string $signature, array $headers): bool;

    /**
     * Parse webhook payload
     */
    public function parseWebhookPayload(string $payload, array $headers): array;

    /**
     * Process webhook for tenant
     */
    public function processWebhook(string $tenantId, array $webhookData, array $configuration): array;

    /**
     * Execute bulk operation
     */
    public function executeBulkOperation(string $operation, array $items, array $configuration, array $options = []): array;

    /**
     * Cleanup resources for tenant
     */
    public function cleanup(string $tenantId): void;

    /**
     * Get rate limit information
     */
    public function getRateLimits(): array;

    /**
     * Get available endpoints
     */
    public function getAvailableEndpoints(): array;

    /**
     * Get required scopes/permissions
     */
    public function getRequiredScopes(): array;
}