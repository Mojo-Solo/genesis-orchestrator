<?php

namespace App\Domains;

use App\Domains\Orchestration\OrchestrationDomain;
use App\Domains\SecurityCompliance\SecurityComplianceDomain;
use App\Domains\TenantManagement\TenantDomain;
use App\Domains\MonitoringObservability\MonitoringDomain;
use App\Domains\ExternalIntegrations\IntegrationDomain;
use App\Domains\AgentManagement\AgentDomain;
use Illuminate\Support\Facades\App;

/**
 * Service Registry for Domain-Driven Architecture
 * 
 * Central registry for all domain services, providing dependency injection
 * and service discovery for the GENESIS architecture transformation.
 * 
 * Replaces 33+ individual services with 6 core domain services.
 */
class ServiceRegistry
{
    private static ?ServiceRegistry $instance = null;
    private array $domains = [];
    private array $serviceMap = [];
    
    private function __construct()
    {
        $this->initializeDomains();
        $this->buildServiceMap();
    }
    
    public static function getInstance(): ServiceRegistry
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Get domain service by name
     */
    public function getDomain(string $domainName): mixed
    {
        if (!isset($this->domains[$domainName])) {
            throw new \InvalidArgumentException("Domain '{$domainName}' not found");
        }
        
        return $this->domains[$domainName];
    }
    
    /**
     * Get service by legacy name (for backward compatibility)
     */
    public function getLegacyService(string $serviceName): mixed
    {
        if (!isset($this->serviceMap[$serviceName])) {
            throw new \InvalidArgumentException("Service '{$serviceName}' not found in domain mapping");
        }
        
        $domainInfo = $this->serviceMap[$serviceName];
        $domain = $this->getDomain($domainInfo['domain']);
        
        return $domainInfo['method'] ? $domain->{$domainInfo['method']}() : $domain;
    }
    
    /**
     * Get all domain services
     */
    public function getAllDomains(): array
    {
        return $this->domains;
    }
    
    /**
     * Check if domain is healthy
     */
    public function checkDomainHealth(string $domainName): array
    {
        $domain = $this->getDomain($domainName);
        
        if (method_exists($domain, 'getHealthStatus')) {
            return $domain->getHealthStatus();
        }
        
        return ['status' => 'unknown', 'message' => 'Health check not implemented'];
    }
    
    /**
     * Get system-wide health status
     */
    public function getSystemHealth(): array
    {
        $health = [
            'status' => 'healthy',
            'domains' => [],
            'degraded_domains' => [],
            'failed_domains' => []
        ];
        
        foreach (array_keys($this->domains) as $domainName) {
            try {
                $domainHealth = $this->checkDomainHealth($domainName);
                $health['domains'][$domainName] = $domainHealth;
                
                if ($domainHealth['status'] === 'degraded') {
                    $health['degraded_domains'][] = $domainName;
                    $health['status'] = 'degraded';
                } elseif ($domainHealth['status'] === 'failed') {
                    $health['failed_domains'][] = $domainName;
                    $health['status'] = 'failed';
                }
            } catch (\Exception $e) {
                $health['failed_domains'][] = $domainName;
                $health['domains'][$domainName] = [
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
                $health['status'] = 'failed';
            }
        }
        
        return $health;
    }
    
    /**
     * Initialize all domain services
     */
    private function initializeDomains(): void
    {
        $this->domains = [
            'orchestration' => App::make(OrchestrationDomain::class),
            'security' => App::make(SecurityComplianceDomain::class),
            'tenant' => App::make(TenantDomain::class),
            'monitoring' => $this->createMonitoringDomain(),
            'integration' => $this->createIntegrationDomain(),
            'agent' => $this->createAgentDomain()
        ];
    }
    
    /**
     * Build legacy service mapping for backward compatibility
     */
    private function buildServiceMap(): void
    {
        $this->serviceMap = [
            // Orchestration Domain Services
            'OrchestrationService' => ['domain' => 'orchestration', 'method' => null],
            'WorkflowOrchestrationService' => ['domain' => 'orchestration', 'method' => 'getWorkflowService'],
            'GenesisOrchestratorIntegrationService' => ['domain' => 'orchestration', 'method' => 'getIntegrationService'],
            'AdvancedRCROptimizer' => ['domain' => 'orchestration', 'method' => 'getRCRService'],
            'MetaLearningAccelerationService' => ['domain' => 'orchestration', 'method' => 'getMetaLearningService'],
            'LatencyOptimizationService' => ['domain' => 'orchestration', 'method' => 'getPerformanceService'],
            'ThroughputAmplificationService' => ['domain' => 'orchestration', 'method' => 'getPerformanceService'],
            'StabilityEnhancementService' => ['domain' => 'orchestration', 'method' => 'getStabilityService'],
            'RequestQueueProcessor' => ['domain' => 'orchestration', 'method' => 'getQueueService'],
            
            // Security Domain Services
            'AdvancedSecurityService' => ['domain' => 'security', 'method' => null],
            'PrivacyComplianceService' => ['domain' => 'security', 'method' => 'getPrivacyService'],
            'PrivacyPolicyService' => ['domain' => 'security', 'method' => 'getPrivacyService'],
            'DataClassificationService' => ['domain' => 'security', 'method' => 'getDataClassificationService'],
            'ThreatDetectionService' => ['domain' => 'security', 'method' => 'getThreatDetectionService'],
            'VaultService' => ['domain' => 'security', 'method' => 'getVaultService'],
            'SSOIntegrationService' => ['domain' => 'security', 'method' => 'getAuthService'],
            'EnhancedRateLimitService' => ['domain' => 'security', 'method' => 'getRateLimitService'],
            
            // Tenant Domain Services
            'TenantService' => ['domain' => 'tenant', 'method' => null],
            'BillingService' => ['domain' => 'tenant', 'method' => 'getBillingService'],
            'FinOpsService' => ['domain' => 'tenant', 'method' => 'getFinOpsService'],
            'CostOptimizationService' => ['domain' => 'tenant', 'method' => 'getCostService'],
            'FinancialReportingService' => ['domain' => 'tenant', 'method' => 'getReportingService'],
            
            // Integration Domain Services
            'FirefliesIntegrationService' => ['domain' => 'integration', 'method' => 'getFirefliesService'],
            'PineconeVectorService' => ['domain' => 'integration', 'method' => 'getPineconeService'],
            'WebhookDeliveryService' => ['domain' => 'integration', 'method' => 'getWebhookService'],
            'APIMarketplaceService' => ['domain' => 'integration', 'method' => 'getMarketplaceService'],
            
            // Monitoring Domain Services
            'RealTimeInsightsService' => ['domain' => 'monitoring', 'method' => 'getInsightsService'],
            'IntelligentTranscriptAnalysisService' => ['domain' => 'monitoring', 'method' => 'getAnalysisService'],
            'DataSynchronizationService' => ['domain' => 'monitoring', 'method' => 'getSyncService'],
            
            // Agent Domain Services
            'PluginArchitectureService' => ['domain' => 'agent', 'method' => 'getPluginService'],
            'CircuitBreakerService' => ['domain' => 'agent', 'method' => 'getCircuitBreakerService']
        ];
    }
    
    /**
     * Create monitoring domain (simplified for now)
     */
    private function createMonitoringDomain(): object
    {
        return new class {
            public function getHealthStatus(): array
            {
                return ['status' => 'healthy', 'message' => 'Monitoring domain operational'];
            }
            
            public function getInsightsService() { return $this; }
            public function getAnalysisService() { return $this; }
            public function getSyncService() { return $this; }
        };
    }
    
    /**
     * Create integration domain (simplified for now)
     */
    private function createIntegrationDomain(): object
    {
        return new class {
            public function getHealthStatus(): array
            {
                return ['status' => 'healthy', 'message' => 'Integration domain operational'];
            }
            
            public function getFirefliesService() { return $this; }
            public function getPineconeService() { return $this; }
            public function getWebhookService() { return $this; }
            public function getMarketplaceService() { return $this; }
        };
    }
    
    /**
     * Create agent domain (simplified for now)
     */
    private function createAgentDomain(): object
    {
        return new class {
            public function getHealthStatus(): array
            {
                return ['status' => 'healthy', 'message' => 'Agent domain operational'];
            }
            
            public function getPluginService() { return $this; }
            public function getCircuitBreakerService() { return $this; }
        };
    }
}