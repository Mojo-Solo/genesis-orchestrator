<?php

namespace App\Services\Monitoring;

/**
 * Interface for Metric Collectors
 * 
 * Defines the contract for all metric collection classes
 */
interface MetricCollectorInterface
{
    /**
     * Collect metrics and return data array
     * 
     * @param array $options Collection options (time_window, filters, etc.)
     * @return array Collected metrics data
     */
    public function collect(array $options = []): array;
}