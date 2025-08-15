<?php

namespace App\Contracts;

interface PluginInterface
{
    /**
     * Get plugin name
     */
    public function getName(): string;

    /**
     * Get plugin version
     */
    public function getVersion(): string;

    /**
     * Get plugin description
     */
    public function getDescription(): string;

    /**
     * Get plugin capabilities
     */
    public function getCapabilities(): array;

    /**
     * Get configuration schema
     */
    public function getConfigurationSchema(): array;

    /**
     * Initialize plugin
     */
    public function initialize(array $configuration): void;

    /**
     * Called when plugin is activated for a tenant
     */
    public function onActivate(string $tenantId, array $configuration): void;

    /**
     * Called when plugin is deactivated for a tenant
     */
    public function onDeactivate(string $tenantId): void;

    /**
     * Get plugin status for a tenant
     */
    public function getStatus(string $tenantId): array;

    /**
     * Validate plugin configuration
     */
    public function validateConfiguration(array $configuration): array;

    /**
     * Handle plugin-specific events
     */
    public function handleEvent(string $eventType, array $eventData, string $tenantId): array;

    /**
     * Get plugin health status
     */
    public function getHealthStatus(): array;

    /**
     * Cleanup plugin resources
     */
    public function cleanup(string $tenantId): void;
}