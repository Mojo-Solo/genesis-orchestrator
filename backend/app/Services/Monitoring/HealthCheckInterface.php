<?php

namespace App\Services\Monitoring;

/**
 * Interface for Health Check Classes
 * 
 * Defines the contract for all health check implementations
 */
interface HealthCheckInterface
{
    /**
     * Execute the health check
     * 
     * @return array Health check results with the following structure:
     * [
     *     'healthy' => bool,           // Overall health status
     *     'critical' => bool,          // Whether failure is critical
     *     'details' => array,          // Detailed check results
     *     'message' => string,         // Summary message
     *     'execution_time_ms' => float // Time taken to execute check
     * ]
     */
    public function execute(): array;
}