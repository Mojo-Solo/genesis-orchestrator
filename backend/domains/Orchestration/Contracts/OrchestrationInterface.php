<?php

namespace App\Domains\Orchestration\Contracts;

/**
 * Orchestration Domain Contract
 * 
 * Defines the contract for the orchestration domain, ensuring consistent
 * implementation of LAG/RCR functionality across the system.
 * 
 * This interface establishes the boundaries for orchestration services
 * and enables dependency injection and testing.
 */
interface OrchestrationInterface
{
    /**
     * Process a query through the complete orchestration pipeline
     * 
     * @param string $query The input query to process
     * @param array $context Additional context for processing
     * @return array Processing results with comprehensive metrics
     * @throws OrchestrationException If orchestration fails
     */
    public function processQuery(string $query, array $context = []): array;
    
    /**
     * Get current orchestration performance metrics
     * 
     * @return array Current metrics including stability, performance, and efficiency
     */
    public function getMetrics(): array;
    
    /**
     * Get health status of the orchestration domain
     * 
     * @return array Health status with detailed check results
     */
    public function getHealthStatus(): array;
    
    /**
     * Reset orchestration metrics
     * Useful for testing and maintenance operations
     * 
     * @return void
     */
    public function resetMetrics(): void;
}