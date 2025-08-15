<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OrchestrationController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\SecurityController;
use App\Controllers\TenantController;
use App\Http\Controllers\Api\FinOpsController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\PerformanceController;

/*
|--------------------------------------------------------------------------
| GENESIS Orchestrator API Routes
|--------------------------------------------------------------------------
|
| API endpoints for orchestrator integration, agent management, and monitoring
|
*/

Route::prefix('api/v1')->group(function () {
    
    // Tenant management endpoints (protected by tenant isolation middleware)
    Route::prefix('tenants')->middleware(['tenant.isolation', 'quota.check'])->group(function () {
        // Create new tenant (admin only)
        Route::post('/', [TenantController::class, 'create'])
            ->name('tenants.create')
            ->withoutMiddleware(['tenant.isolation', 'quota.check']);
        
        // List all tenants (admin only)
        Route::get('/', [TenantController::class, 'index'])
            ->name('tenants.index')
            ->withoutMiddleware(['tenant.isolation', 'quota.check']);
        
        // Get current tenant information
        Route::get('/current', [TenantController::class, 'show'])
            ->name('tenants.current');
        
        // Get specific tenant information
        Route::get('/{tenantId}', [TenantController::class, 'show'])
            ->name('tenants.show');
        
        // Update tenant information
        Route::put('/{tenantId?}', [TenantController::class, 'update'])
            ->name('tenants.update');
        
        // Update tenant tier
        Route::put('/{tenantId}/tier', [TenantController::class, 'updateTier'])
            ->name('tenants.updateTier');
        
        // Suspend tenant
        Route::post('/{tenantId}/suspend', [TenantController::class, 'suspend'])
            ->name('tenants.suspend');
        
        // Reactivate tenant
        Route::post('/{tenantId}/reactivate', [TenantController::class, 'reactivate'])
            ->name('tenants.reactivate');
        
        // Tenant user management
        Route::prefix('{tenantId}/users')->group(function () {
            Route::get('/', [TenantController::class, 'users'])->name('tenants.users');
            Route::post('/', [TenantController::class, 'addUser'])->name('tenants.addUser');
            Route::put('/{userId}', [TenantController::class, 'updateUser'])->name('tenants.updateUser');
            Route::delete('/{userId}', [TenantController::class, 'removeUser'])->name('tenants.removeUser');
        });
        
        // Tenant analytics and reporting
        Route::get('/{tenantId}/analytics', [TenantController::class, 'analytics'])
            ->name('tenants.analytics');
        
        // Tenant billing information
        Route::get('/{tenantId}/billing', [TenantController::class, 'billing'])
            ->name('tenants.billing');
        
        // Tenant quota usage
        Route::get('/{tenantId}/quotas', [TenantController::class, 'quotas'])
            ->name('tenants.quotas');
    });
    
    // Orchestration endpoints (protected by tenant isolation and quota checking)
    Route::prefix('orchestration')->middleware(['tenant.isolation', 'quota.check:orchestration_runs'])->group(function () {
        // Start a new orchestration run
        Route::post('/start', [OrchestrationController::class, 'start'])
            ->name('orchestration.start');
        
        // Complete an orchestration run
        Route::post('/complete/{runId}', [OrchestrationController::class, 'complete'])
            ->name('orchestration.complete');
        
        // Get run status
        Route::get('/status/{runId}', [OrchestrationController::class, 'status'])
            ->name('orchestration.status');
        
        // Get run statistics
        Route::get('/stats', [OrchestrationController::class, 'statistics'])
            ->name('orchestration.stats');
        
        // Get run history
        Route::get('/history', [OrchestrationController::class, 'history'])
            ->name('orchestration.history');
    });
    
    // Agent execution endpoints (protected by tenant isolation and quota checking)
    Route::prefix('agents')->middleware(['tenant.isolation', 'quota.check:agent_executions'])->group(function () {
        // Record agent execution start
        Route::post('/execute', [AgentController::class, 'execute'])
            ->name('agents.execute');
        
        // Complete agent execution
        Route::post('/complete/{executionId}', [AgentController::class, 'complete'])
            ->name('agents.complete');
        
        // Get agent performance metrics
        Route::get('/performance', [AgentController::class, 'performance'])
            ->name('agents.performance');
        
        // Get agent capabilities
        Route::get('/capabilities', [AgentController::class, 'capabilities'])
            ->name('agents.capabilities');
    });
    
    // Router metrics endpoints
    Route::prefix('router')->group(function () {
        // Record router metrics
        Route::post('/metrics', [OrchestrationController::class, 'recordRouterMetrics'])
            ->name('router.metrics');
        
        // Get router efficiency stats
        Route::get('/efficiency', [OrchestrationController::class, 'routerEfficiency'])
            ->name('router.efficiency');
    });
    
    // Stability tracking endpoints
    Route::prefix('stability')->group(function () {
        // Track stability test
        Route::post('/track', [OrchestrationController::class, 'trackStability'])
            ->name('stability.track');
        
        // Get stability metrics
        Route::get('/metrics', [OrchestrationController::class, 'stabilityMetrics'])
            ->name('stability.metrics');
    });
    
    // Security audit endpoints
    Route::prefix('security')->group(function () {
        // Log security event
        Route::post('/audit', [SecurityController::class, 'logAudit'])
            ->name('security.audit');
        
        // Check IP reputation
        Route::get('/check-ip/{ip}', [SecurityController::class, 'checkIp'])
            ->name('security.checkIp');
        
        // Get security events
        Route::get('/events', [SecurityController::class, 'events'])
            ->name('security.events');
    });
    
    // Memory management endpoints (protected by tenant isolation and quota checking)
    Route::prefix('memory')->middleware(['tenant.isolation', 'quota.check:memory_items'])->group(function () {
        // Store memory item
        Route::post('/store', [OrchestrationController::class, 'storeMemory'])
            ->name('memory.store');
        
        // Retrieve memory item
        Route::get('/retrieve/{key}', [OrchestrationController::class, 'retrieveMemory'])
            ->name('memory.retrieve');
        
        // Clean expired memory
        Route::delete('/cleanup', [OrchestrationController::class, 'cleanupMemory'])
            ->name('memory.cleanup');
    });
    
    // FinOps and Cost Management endpoints
    Route::prefix('finops')->middleware(['tenant.isolation'])->group(function () {
        // Dashboard and overview
        Route::get('/dashboard', [FinOpsController::class, 'getDashboard'])
            ->name('finops.dashboard');
        
        // Cost attribution and analytics
        Route::get('/cost-attribution', [FinOpsController::class, 'getCostAttribution'])
            ->name('finops.costAttribution');
        
        Route::get('/usage-analytics', [FinOpsController::class, 'getUsageAnalytics'])
            ->name('finops.usageAnalytics');
        
        // Usage recording
        Route::post('/record-usage', [FinOpsController::class, 'recordUsage'])
            ->name('finops.recordUsage');
        
        // Budget management
        Route::get('/budgets', [FinOpsController::class, 'getBudgets'])
            ->name('finops.budgets');
        
        Route::post('/budgets', [FinOpsController::class, 'createBudget'])
            ->name('finops.createBudget');
        
        Route::put('/budgets/{budgetId}', [FinOpsController::class, 'updateBudget'])
            ->name('finops.updateBudget');
        
        // Alert management
        Route::get('/alerts', [FinOpsController::class, 'getAlerts'])
            ->name('finops.alerts');
        
        Route::post('/alerts/{alertId}/acknowledge', [FinOpsController::class, 'acknowledgeAlert'])
            ->name('finops.acknowledgeAlert');
        
        Route::post('/alerts/{alertId}/resolve', [FinOpsController::class, 'resolveAlert'])
            ->name('finops.resolveAlert');
        
        // Optimization recommendations
        Route::get('/recommendations', [FinOpsController::class, 'getOptimizationRecommendations'])
            ->name('finops.recommendations');
        
        // Forecasting
        Route::get('/forecast/{days?}', [FinOpsController::class, 'getCostForecast'])
            ->name('finops.forecast');
        
        // Reports and exports
        Route::post('/export', [FinOpsController::class, 'exportCostReport'])
            ->name('finops.export');
    });
    
    // Billing and Payment endpoints
    Route::prefix('billing')->middleware(['tenant.isolation'])->group(function () {
        // Billing summary and overview
        Route::get('/summary', [BillingController::class, 'getBillingSummary'])
            ->name('billing.summary');
        
        // Invoice management
        Route::post('/generate-invoice', [BillingController::class, 'generateInvoice'])
            ->name('billing.generateInvoice');
        
        Route::get('/history', [BillingController::class, 'getBillingHistory'])
            ->name('billing.history');
        
        // Subscription management
        Route::post('/subscription', [BillingController::class, 'createSubscription'])
            ->name('billing.createSubscription');
        
        Route::delete('/subscription', [BillingController::class, 'cancelSubscription'])
            ->name('billing.cancelSubscription');
        
        // Payment methods
        Route::put('/payment-method', [BillingController::class, 'updatePaymentMethod'])
            ->name('billing.updatePaymentMethod');
        
        // Automated billing configuration
        Route::post('/automated-billing', [BillingController::class, 'setupAutomatedBilling'])
            ->name('billing.setupAutomatedBilling');
        
        // Pricing information
        Route::get('/pricing-plans', [BillingController::class, 'getPricingPlans'])
            ->name('billing.pricingPlans');
        
        // Reports
        Route::post('/report', [BillingController::class, 'generateBillingReport'])
            ->name('billing.report');
        
        // Admin endpoints (process billing for all tenants)
        Route::post('/process-all', [BillingController::class, 'processAllBilling'])
            ->name('billing.processAll')
            ->middleware('admin.required');
    });
});

// Webhook endpoints for external integrations
Route::prefix('webhooks')->group(function () {
    // Temporal workflow completion webhook
    Route::post('/temporal/complete', [OrchestrationController::class, 'temporalWebhook'])
        ->middleware('verify.hmac')
        ->name('webhooks.temporal');
    
    // OpenTelemetry metrics push
    Route::post('/otel/metrics', [OrchestrationController::class, 'otelMetrics'])
        ->middleware('verify.hmac')
        ->name('webhooks.otel');
    
    // Stripe billing webhooks
    Route::post('/stripe', [BillingController::class, 'handleWebhook'])
        ->name('webhooks.stripe');
});

// Performance Profiling API endpoints
Route::prefix('api/v1/performance')->middleware(['tenant.isolation'])->group(function () {
    // Get current performance metrics
    Route::get('/metrics/current', [PerformanceController::class, 'getCurrentMetrics'])
        ->name('performance.metrics.current');
    
    // Get performance metrics history
    Route::get('/metrics/history', [PerformanceController::class, 'getMetricsHistory'])
        ->name('performance.metrics.history');
    
    // Get performance analytics and insights
    Route::get('/analytics', [PerformanceController::class, 'getPerformanceAnalytics'])
        ->name('performance.analytics');
    
    // Record external performance metric
    Route::post('/metrics', [PerformanceController::class, 'recordMetric'])
        ->name('performance.metrics.record');
    
    // Get performance benchmarks and baselines
    Route::get('/benchmarks', [PerformanceController::class, 'getBenchmarks'])
        ->name('performance.benchmarks');
    
    // Export performance data
    Route::post('/export', [PerformanceController::class, 'exportPerformanceData'])
        ->name('performance.export');
});

// External Integrations API endpoints
Route::prefix('api/v1/integrations')->group(function () {
    
    // SSO Integration endpoints
    Route::prefix('sso')->group(function () {
        // Get SSO configuration for tenant
        Route::get('/configuration', [SSOController::class, 'getConfiguration'])
            ->name('sso.configuration');
        
        // Initiate SSO login
        Route::post('/login', [SSOController::class, 'initiateLogin'])
            ->name('sso.login');
        
        // SAML endpoints
        Route::prefix('saml')->group(function () {
            Route::post('/callback', [SSOController::class, 'handleSAMLCallback'])
                ->name('sso.saml.callback');
            
            Route::get('/metadata', [SSOController::class, 'getSAMLMetadata'])
                ->name('sso.saml.metadata');
        });
        
        // OIDC endpoints
        Route::prefix('oidc')->group(function () {
            Route::get('/callback', [SSOController::class, 'handleOIDCCallback'])
                ->name('sso.oidc.callback');
        });
        
        // Logout endpoint
        Route::post('/logout', [SSOController::class, 'initiateLogout'])
            ->middleware(['tenant.isolation'])
            ->name('sso.logout');
        
        // Test SSO configuration
        Route::post('/test', [SSOController::class, 'testConfiguration'])
            ->middleware(['tenant.isolation'])
            ->name('sso.test');
    });
    
    // API Marketplace endpoints
    Route::prefix('marketplace')->middleware(['tenant.isolation', 'quota.check:api_calls'])->group(function () {
        // Get available connectors
        Route::get('/connectors', [APIMarketplaceController::class, 'getConnectors'])
            ->name('marketplace.connectors');
        
        // Configure connector
        Route::post('/connectors/configure', [APIMarketplaceController::class, 'configureConnector'])
            ->name('marketplace.configure');
        
        // Execute API call
        Route::post('/connectors/execute', [APIMarketplaceController::class, 'executeAPICall'])
            ->name('marketplace.execute');
        
        // Get connector metrics
        Route::get('/connectors/{connectorName}/metrics', [APIMarketplaceController::class, 'getConnectorMetrics'])
            ->name('marketplace.metrics');
        
        // Get webhook configuration
        Route::get('/connectors/{connectorName}/webhook', [APIMarketplaceController::class, 'getWebhookConfiguration'])
            ->name('marketplace.webhook.config');
        
        // Execute bulk operation
        Route::post('/connectors/bulk', [APIMarketplaceController::class, 'executeBulkOperation'])
            ->name('marketplace.bulk');
        
        // Disconnect connector
        Route::delete('/connectors/{connectorName}', [APIMarketplaceController::class, 'disconnectConnector'])
            ->name('marketplace.disconnect');
        
        // Get connector documentation
        Route::get('/connectors/{connectorName}/docs', [APIMarketplaceController::class, 'getConnectorDocumentation'])
            ->name('marketplace.docs');
        
        // Test connector configuration
        Route::post('/connectors/test', [APIMarketplaceController::class, 'testConnectorConfiguration'])
            ->name('marketplace.test');
        
        // Get marketplace statistics
        Route::get('/statistics', [APIMarketplaceController::class, 'getMarketplaceStatistics'])
            ->name('marketplace.statistics');
    });
    
    // Webhook Management endpoints
    Route::prefix('webhooks')->middleware(['tenant.isolation'])->group(function () {
        // Register webhook endpoint
        Route::post('/', [WebhookController::class, 'registerWebhook'])
            ->name('webhooks.register');
        
        // Get tenant webhooks
        Route::get('/', [WebhookController::class, 'getTenantWebhooks'])
            ->name('webhooks.list');
        
        // Update webhook
        Route::put('/{webhookId}', [WebhookController::class, 'updateWebhook'])
            ->name('webhooks.update');
        
        // Delete webhook
        Route::delete('/{webhookId}', [WebhookController::class, 'deleteWebhook'])
            ->name('webhooks.delete');
        
        // Test webhook
        Route::post('/{webhookId}/test', [WebhookController::class, 'testWebhook'])
            ->name('webhooks.test');
        
        // Get webhook statistics
        Route::get('/{webhookId}/statistics', [WebhookController::class, 'getWebhookStatistics'])
            ->name('webhooks.statistics');
        
        // Activate/deactivate webhook
        Route::post('/{webhookId}/activate', [WebhookController::class, 'activateWebhook'])
            ->name('webhooks.activate');
        
        Route::post('/{webhookId}/deactivate', [WebhookController::class, 'deactivateWebhook'])
            ->name('webhooks.deactivate');
    });
    
    // External webhook receivers (no authentication)
    Route::prefix('webhooks')->group(function () {
        Route::post('/{connectorName}', [APIMarketplaceController::class, 'handleWebhook'])
            ->name('webhooks.external');
    });
    
    // Plugin Management endpoints
    Route::prefix('plugins')->middleware(['tenant.isolation'])->group(function () {
        // Get available plugins
        Route::get('/', [PluginController::class, 'getAvailablePlugins'])
            ->name('plugins.list');
        
        // Install plugin
        Route::post('/install', [PluginController::class, 'installPlugin'])
            ->name('plugins.install');
        
        // Uninstall plugin
        Route::delete('/{pluginId}', [PluginController::class, 'uninstallPlugin'])
            ->name('plugins.uninstall');
        
        // Activate plugin
        Route::post('/{pluginId}/activate', [PluginController::class, 'activatePlugin'])
            ->name('plugins.activate');
        
        // Deactivate plugin
        Route::post('/{pluginId}/deactivate', [PluginController::class, 'deactivatePlugin'])
            ->name('plugins.deactivate');
        
        // Execute plugin method
        Route::post('/{pluginName}/execute', [PluginController::class, 'executePlugin'])
            ->name('plugins.execute');
        
        // Get plugin statistics
        Route::get('/{pluginName}/statistics', [PluginController::class, 'getPluginStatistics'])
            ->name('plugins.statistics');
        
        // Get plugin configuration
        Route::get('/{pluginId}/configuration', [PluginController::class, 'getPluginConfiguration'])
            ->name('plugins.configuration');
        
        // Update plugin configuration
        Route::put('/{pluginId}/configuration', [PluginController::class, 'updatePluginConfiguration'])
            ->name('plugins.configuration.update');
    });
    
    // Data Synchronization endpoints
    Route::prefix('sync')->middleware(['tenant.isolation', 'quota.check:sync_jobs'])->group(function () {
        // Create sync job
        Route::post('/jobs', [SyncController::class, 'createSyncJob'])
            ->name('sync.create');
        
        // Get sync jobs
        Route::get('/jobs', [SyncController::class, 'getSyncJobs'])
            ->name('sync.list');
        
        // Get sync job details
        Route::get('/jobs/{syncId}', [SyncController::class, 'getSyncJob'])
            ->name('sync.details');
        
        // Update sync job
        Route::put('/jobs/{syncId}', [SyncController::class, 'updateSyncJob'])
            ->name('sync.update');
        
        // Delete sync job
        Route::delete('/jobs/{syncId}', [SyncController::class, 'deleteSyncJob'])
            ->name('sync.delete');
        
        // Execute sync job manually
        Route::post('/jobs/{syncId}/execute', [SyncController::class, 'executeSyncJob'])
            ->name('sync.execute');
        
        // Get sync execution history
        Route::get('/jobs/{syncId}/executions', [SyncController::class, 'getSyncExecutions'])
            ->name('sync.executions');
        
        // Get sync statistics
        Route::get('/jobs/{syncId}/statistics', [SyncController::class, 'getSyncStatistics'])
            ->name('sync.statistics');
        
        // Pause/resume sync job
        Route::post('/jobs/{syncId}/pause', [SyncController::class, 'pauseSyncJob'])
            ->name('sync.pause');
        
        Route::post('/jobs/{syncId}/resume', [SyncController::class, 'resumeSyncJob'])
            ->name('sync.resume');
    });
    
    // Integration Health and Monitoring endpoints
    Route::prefix('health')->middleware(['tenant.isolation'])->group(function () {
        // Get overall integration health
        Route::get('/', [IntegrationHealthController::class, 'getOverallHealth'])
            ->name('integrations.health');
        
        // Get specific integration health
        Route::get('/{integrationType}/{integrationName}', [IntegrationHealthController::class, 'getIntegrationHealth'])
            ->name('integrations.health.specific');
        
        // Force health check
        Route::post('/check', [IntegrationHealthController::class, 'forceHealthCheck'])
            ->name('integrations.health.check');
        
        // Get health history
        Route::get('/history', [IntegrationHealthController::class, 'getHealthHistory'])
            ->name('integrations.health.history');
    });
    
    // Integration Analytics and Reporting endpoints
    Route::prefix('analytics')->middleware(['tenant.isolation'])->group(function () {
        // Get integration usage analytics
        Route::get('/usage', [IntegrationAnalyticsController::class, 'getUsageAnalytics'])
            ->name('integrations.analytics.usage');
        
        // Get performance analytics
        Route::get('/performance', [IntegrationAnalyticsController::class, 'getPerformanceAnalytics'])
            ->name('integrations.analytics.performance');
        
        // Get error analytics
        Route::get('/errors', [IntegrationAnalyticsController::class, 'getErrorAnalytics'])
            ->name('integrations.analytics.errors');
        
        // Get cost analytics
        Route::get('/costs', [IntegrationAnalyticsController::class, 'getCostAnalytics'])
            ->name('integrations.analytics.costs');
        
        // Export analytics report
        Route::post('/export', [IntegrationAnalyticsController::class, 'exportAnalytics'])
            ->name('integrations.analytics.export');
    });
});