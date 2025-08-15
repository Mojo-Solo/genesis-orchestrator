<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantResourceUsage;
use App\Models\OrchestrationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class FinOpsService
{
    const CACHE_TTL = 300; // 5 minutes
    const BUDGET_ALERT_THRESHOLDS = [0.7, 0.8, 0.9, 1.0]; // 70%, 80%, 90%, 100%
    
    /**
     * Record usage and calculate costs in real-time
     */
    public function recordUsageWithAttribution(
        string $tenantId,
        string $userId,
        string $resourceType,
        array $usageData
    ): array {
        try {
            DB::beginTransaction();
            
            // Record base usage
            $usage = TenantResourceUsage::recordUsage(
                $tenantId,
                $resourceType,
                $usageData['amount'] ?? 1,
                $usageData['metrics'] ?? []
            );
            
            // Add user attribution
            $detailedMetrics = $usage->detailed_metrics ?? [];
            $detailedMetrics['user_attribution'][$userId] = 
                ($detailedMetrics['user_attribution'][$userId] ?? 0) + ($usageData['amount'] ?? 1);
            
            // Add resource-specific attribution
            if (isset($usageData['resource_id'])) {
                $detailedMetrics['resource_attribution'][$usageData['resource_id']] =
                    ($detailedMetrics['resource_attribution'][$usageData['resource_id']] ?? 0) + ($usageData['amount'] ?? 1);
            }
            
            // Add organization/department attribution if provided
            if (isset($usageData['department'])) {
                $detailedMetrics['department_attribution'][$usageData['department']] =
                    ($detailedMetrics['department_attribution'][$usageData['department']] ?? 0) + ($usageData['amount'] ?? 1);
            }
            
            $usage->detailed_metrics = $detailedMetrics;
            $usage->save();
            
            // Check budget thresholds
            $budgetStatus = $this->checkBudgetThresholds($tenantId);
            
            // Clear relevant caches
            $this->clearUsageCaches($tenantId);
            
            DB::commit();
            
            return [
                'usage_id' => $usage->id,
                'cost_impact' => $usage->cost_per_unit * ($usageData['amount'] ?? 1),
                'budget_status' => $budgetStatus,
                'efficiency_score' => $usage->getEfficiencyScore()
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('FinOps usage recording failed', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'resource_type' => $resourceType,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Calculate costs with multiple attribution models
     */
    public function calculateCostAttribution(string $tenantId, Carbon $startDate, Carbon $endDate): array
    {
        $cacheKey = "cost_attribution_{$tenantId}_{$startDate->format('Y-m-d')}_{$endDate->format('Y-m-d')}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenantId, $startDate, $endDate) {
            $usageRecords = TenantResourceUsage::forTenant($tenantId)
                ->forDateRange($startDate, $endDate)
                ->get();
            
            $attribution = [
                'by_user' => [],
                'by_resource_type' => [],
                'by_department' => [],
                'by_resource' => [],
                'total_cost' => 0,
                'cost_trends' => []
            ];
            
            foreach ($usageRecords as $record) {
                $attribution['total_cost'] += $record->total_cost;
                
                // Resource type attribution
                $attribution['by_resource_type'][$record->resource_type] = 
                    ($attribution['by_resource_type'][$record->resource_type] ?? 0) + $record->total_cost;
                
                // User attribution from detailed metrics
                $userAttribution = $record->detailed_metrics['user_attribution'] ?? [];
                foreach ($userAttribution as $userId => $usage) {
                    $userCost = $usage * $record->cost_per_unit;
                    $attribution['by_user'][$userId] = ($attribution['by_user'][$userId] ?? 0) + $userCost;
                }
                
                // Department attribution
                $deptAttribution = $record->detailed_metrics['department_attribution'] ?? [];
                foreach ($deptAttribution as $dept => $usage) {
                    $deptCost = $usage * $record->cost_per_unit;
                    $attribution['by_department'][$dept] = ($attribution['by_department'][$dept] ?? 0) + $deptCost;
                }
                
                // Resource attribution
                $resourceAttribution = $record->detailed_metrics['resource_attribution'] ?? [];
                foreach ($resourceAttribution as $resourceId => $usage) {
                    $resourceCost = $usage * $record->cost_per_unit;
                    $attribution['by_resource'][$resourceId] = ($attribution['by_resource'][$resourceId] ?? 0) + $resourceCost;
                }
                
                // Cost trends
                $dateKey = $record->usage_date->format('Y-m-d');
                $attribution['cost_trends'][$dateKey] = ($attribution['cost_trends'][$dateKey] ?? 0) + $record->total_cost;
            }
            
            // Sort by cost descending
            arsort($attribution['by_user']);
            arsort($attribution['by_resource_type']);
            arsort($attribution['by_department']);
            arsort($attribution['by_resource']);
            ksort($attribution['cost_trends']);
            
            return $attribution;
        });
    }
    
    /**
     * Check budget thresholds and trigger alerts
     */
    public function checkBudgetThresholds(string $tenantId): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        $currentUsage = $this->getTenantCurrentMonthUsage($tenantId);
        
        $budgets = $tenant->config['budgets'] ?? [];
        $alerts = [];
        
        foreach ($budgets as $resourceType => $budget) {
            $usage = $currentUsage[$resourceType] ?? 0;
            $percentage = $budget > 0 ? ($usage / $budget) * 100 : 0;
            
            foreach (self::BUDGET_ALERT_THRESHOLDS as $threshold) {
                if ($percentage >= ($threshold * 100) && !$this->hasRecentAlert($tenantId, $resourceType, $threshold)) {
                    $alert = $this->triggerBudgetAlert($tenantId, $resourceType, $usage, $budget, $threshold);
                    $alerts[] = $alert;
                }
            }
        }
        
        return [
            'total_alerts' => count($alerts),
            'alerts' => $alerts,
            'budget_utilization' => $this->calculateBudgetUtilization($tenantId)
        ];
    }
    
    /**
     * Generate cost optimization recommendations
     */
    public function generateOptimizationRecommendations(string $tenantId): array
    {
        $cacheKey = "optimization_recommendations_{$tenantId}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL * 2, function () use ($tenantId) {
            $recommendations = [];
            
            // Analyze usage patterns
            $usageAnalysis = $this->analyzeUsagePatterns($tenantId);
            
            // High error rate resources
            $highErrorResources = TenantResourceUsage::forTenant($tenantId)
                ->currentMonth()
                ->where('error_rate_percent', '>', 5)
                ->get();
            
            foreach ($highErrorResources as $resource) {
                $recommendations[] = [
                    'type' => 'error_reduction',
                    'priority' => 'high',
                    'resource_type' => $resource->resource_type,
                    'current_error_rate' => $resource->error_rate_percent,
                    'potential_savings' => $this->calculateErrorCostImpact($resource),
                    'recommendation' => "Reduce error rate from {$resource->error_rate_percent}% to improve efficiency",
                    'estimated_impact' => 'Up to 15% cost reduction'
                ];
            }
            
            // Underutilized resources
            $underutilizedHours = $this->findUnderutilizedHours($tenantId);
            if (!empty($underutilizedHours)) {
                $recommendations[] = [
                    'type' => 'scheduling_optimization',
                    'priority' => 'medium',
                    'underutilized_hours' => $underutilizedHours,
                    'potential_savings' => $this->calculateSchedulingOptimization($tenantId),
                    'recommendation' => 'Optimize resource scheduling to avoid peak pricing',
                    'estimated_impact' => 'Up to 20% cost reduction'
                ];
            }
            
            // Resource right-sizing
            $oversizedResources = $this->identifyOversizedResources($tenantId);
            foreach ($oversizedResources as $resource) {
                $recommendations[] = [
                    'type' => 'right_sizing',
                    'priority' => 'medium',
                    'resource_type' => $resource['type'],
                    'current_usage' => $resource['usage'],
                    'recommended_size' => $resource['recommended'],
                    'potential_savings' => $resource['savings'],
                    'recommendation' => "Right-size {$resource['type']} resources",
                    'estimated_impact' => "{$resource['savings_percent']}% cost reduction"
                ];
            }
            
            // Bulk processing opportunities
            $bulkOpportunities = $this->identifyBulkProcessingOpportunities($tenantId);
            if ($bulkOpportunities['potential_savings'] > 0) {
                $recommendations[] = [
                    'type' => 'bulk_processing',
                    'priority' => 'low',
                    'operations_count' => $bulkOpportunities['count'],
                    'potential_savings' => $bulkOpportunities['potential_savings'],
                    'recommendation' => 'Batch similar operations to reduce per-operation overhead',
                    'estimated_impact' => '5-10% cost reduction'
                ];
            }
            
            return [
                'recommendations' => $recommendations,
                'total_potential_savings' => array_sum(array_column($recommendations, 'potential_savings')),
                'priority_breakdown' => [
                    'high' => count(array_filter($recommendations, fn($r) => $r['priority'] === 'high')),
                    'medium' => count(array_filter($recommendations, fn($r) => $r['priority'] === 'medium')),
                    'low' => count(array_filter($recommendations, fn($r) => $r['priority'] === 'low'))
                ],
                'generated_at' => Carbon::now()->toISOString()
            ];
        });
    }
    
    /**
     * Generate cost forecasting with trend analysis
     */
    public function generateCostForecast(string $tenantId, int $forecastDays = 30): array
    {
        $cacheKey = "cost_forecast_{$tenantId}_{$forecastDays}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenantId, $forecastDays) {
            // Get historical data for trend analysis
            $historicalData = $this->getHistoricalUsageData($tenantId, 90); // 90 days of history
            
            $forecast = [];
            $totalForecastCost = 0;
            
            foreach ($historicalData['by_resource_type'] as $resourceType => $data) {
                $trend = $this->calculateTrend($data);
                $seasonality = $this->calculateSeasonality($data);
                
                $resourceForecast = [];
                $currentDate = Carbon::now();
                
                for ($i = 1; $i <= $forecastDays; $i++) {
                    $forecastDate = $currentDate->copy()->addDays($i);
                    $baseCost = $this->getBaseCostForResource($resourceType, $tenantId);
                    
                    // Apply trend
                    $trendMultiplier = 1 + ($trend * $i / 30); // Monthly trend applied daily
                    
                    // Apply seasonality (weekly patterns)
                    $seasonalMultiplier = $seasonality[$forecastDate->dayOfWeek] ?? 1;
                    
                    // Add some randomness for confidence intervals
                    $forecastCost = $baseCost * $trendMultiplier * $seasonalMultiplier;
                    
                    $resourceForecast[] = [
                        'date' => $forecastDate->format('Y-m-d'),
                        'predicted_cost' => round($forecastCost, 2),
                        'confidence_low' => round($forecastCost * 0.85, 2),
                        'confidence_high' => round($forecastCost * 1.15, 2)
                    ];
                    
                    $totalForecastCost += $forecastCost;
                }
                
                $forecast[$resourceType] = [
                    'daily_forecast' => $resourceForecast,
                    'total_forecast' => array_sum(array_column($resourceForecast, 'predicted_cost')),
                    'trend' => $trend,
                    'confidence' => $this->calculateForecastConfidence($data)
                ];
            }
            
            return [
                'forecast_period' => $forecastDays,
                'total_forecast_cost' => round($totalForecastCost, 2),
                'by_resource_type' => $forecast,
                'summary' => [
                    'daily_average' => round($totalForecastCost / $forecastDays, 2),
                    'monthly_projection' => round($totalForecastCost * (30 / $forecastDays), 2),
                    'growth_rate' => $this->calculateOverallGrowthRate($historicalData),
                    'confidence_score' => $this->calculateOverallConfidence($forecast)
                ],
                'generated_at' => Carbon::now()->toISOString()
            ];
        });
    }
    
    /**
     * Get comprehensive usage analytics
     */
    public function getUsageAnalytics(string $tenantId, Carbon $startDate, Carbon $endDate): array
    {
        $cacheKey = "usage_analytics_{$tenantId}_{$startDate->format('Y-m-d')}_{$endDate->format('Y-m-d')}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenantId, $startDate, $endDate) {
            $records = TenantResourceUsage::forTenant($tenantId)
                ->forDateRange($startDate, $endDate)
                ->orderBy('usage_date')
                ->get();
            
            $analytics = [
                'summary' => [
                    'total_cost' => $records->sum('total_cost'),
                    'total_usage' => $records->sum('total_usage'),
                    'total_errors' => $records->sum('total_errors'),
                    'average_efficiency' => $records->avg('error_rate_percent'),
                    'peak_usage_day' => null,
                    'lowest_cost_day' => null
                ],
                'daily_breakdown' => [],
                'resource_efficiency' => [],
                'usage_patterns' => [],
                'cost_distribution' => [],
                'performance_metrics' => []
            ];
            
            // Daily breakdown
            $dailyData = $records->groupBy('usage_date')->map(function ($dayRecords) {
                return [
                    'total_cost' => $dayRecords->sum('total_cost'),
                    'total_usage' => $dayRecords->sum('total_usage'),
                    'total_errors' => $dayRecords->sum('total_errors'),
                    'resource_types' => $dayRecords->pluck('resource_type')->unique()->count(),
                    'efficiency_score' => $dayRecords->avg('error_rate_percent')
                ];
            });
            
            $analytics['daily_breakdown'] = $dailyData->toArray();
            
            // Find peak usage day
            $peakDay = $dailyData->sortByDesc('total_usage')->first();
            $analytics['summary']['peak_usage_day'] = $peakDay;
            
            // Resource efficiency analysis
            $resourceEfficiency = $records->groupBy('resource_type')->map(function ($resourceRecords) {
                $efficiency = $resourceRecords->map->getEfficiencyScore();
                return [
                    'average_efficiency' => $efficiency->avg(),
                    'total_cost' => $resourceRecords->sum('total_cost'),
                    'total_usage' => $resourceRecords->sum('total_usage'),
                    'error_rate' => $resourceRecords->avg('error_rate_percent'),
                    'response_time' => $resourceRecords->avg('average_response_time_ms')
                ];
            });
            
            $analytics['resource_efficiency'] = $resourceEfficiency->toArray();
            
            // Usage patterns (hourly distribution)
            $usagePatterns = $this->analyzeUsagePatterns($tenantId, $startDate, $endDate);
            $analytics['usage_patterns'] = $usagePatterns;
            
            // Cost distribution
            $costDistribution = $records->groupBy('resource_type')
                ->map->sum('total_cost')
                ->sort()
                ->reverse();
            
            $analytics['cost_distribution'] = $costDistribution->toArray();
            
            // Performance metrics
            $analytics['performance_metrics'] = [
                'average_response_time' => $records->avg('average_response_time_ms'),
                'p95_response_time' => $records->avg('p95_response_time_ms'),
                'overall_error_rate' => ($records->sum('total_errors') / $records->sum('total_usage')) * 100,
                'reliability_score' => 100 - (($records->sum('total_errors') / $records->sum('total_usage')) * 100)
            ];
            
            return $analytics;
        });
    }
    
    // Private helper methods
    
    private function getTenantCurrentMonthUsage(string $tenantId): array
    {
        return TenantResourceUsage::forTenant($tenantId)
            ->currentMonth()
            ->selectRaw('resource_type, SUM(total_cost) as total_cost')
            ->groupBy('resource_type')
            ->pluck('total_cost', 'resource_type')
            ->toArray();
    }
    
    private function hasRecentAlert(string $tenantId, string $resourceType, float $threshold): bool
    {
        return Cache::has("budget_alert_{$tenantId}_{$resourceType}_{$threshold}");
    }
    
    private function triggerBudgetAlert(string $tenantId, string $resourceType, float $usage, float $budget, float $threshold): array
    {
        $alert = [
            'tenant_id' => $tenantId,
            'resource_type' => $resourceType,
            'threshold' => $threshold * 100,
            'current_usage' => $usage,
            'budget' => $budget,
            'percentage' => ($usage / $budget) * 100,
            'timestamp' => Carbon::now()->toISOString()
        ];
        
        // Cache alert to prevent duplicates
        Cache::put("budget_alert_{$tenantId}_{$resourceType}_{$threshold}", true, 3600);
        
        // Log alert
        Log::warning('Budget threshold exceeded', $alert);
        
        return $alert;
    }
    
    private function calculateBudgetUtilization(string $tenantId): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        $budgets = $tenant->config['budgets'] ?? [];
        $currentUsage = $this->getTenantCurrentMonthUsage($tenantId);
        
        $utilization = [];
        foreach ($budgets as $resourceType => $budget) {
            $usage = $currentUsage[$resourceType] ?? 0;
            $utilization[$resourceType] = [
                'budget' => $budget,
                'usage' => $usage,
                'percentage' => $budget > 0 ? ($usage / $budget) * 100 : 0,
                'remaining' => max(0, $budget - $usage)
            ];
        }
        
        return $utilization;
    }
    
    private function analyzeUsagePatterns(string $tenantId, Carbon $startDate = null, Carbon $endDate = null): array
    {
        $startDate = $startDate ?: Carbon::now()->subDays(30);
        $endDate = $endDate ?: Carbon::now();
        
        $records = TenantResourceUsage::forTenant($tenantId)
            ->forDateRange($startDate, $endDate)
            ->get();
        
        $patterns = [
            'hourly_distribution' => array_fill(0, 24, 0),
            'daily_distribution' => array_fill(0, 7, 0),
            'peak_hours' => [],
            'low_usage_periods' => []
        ];
        
        foreach ($records as $record) {
            $hourlyBreakdown = $record->hourly_breakdown ?? [];
            for ($hour = 0; $hour < 24; $hour++) {
                $patterns['hourly_distribution'][$hour] += $hourlyBreakdown[$hour] ?? 0;
            }
            
            $dayOfWeek = $record->usage_date->dayOfWeek;
            $patterns['daily_distribution'][$dayOfWeek] += $record->total_usage;
        }
        
        // Identify peak hours
        $maxHourlyUsage = max($patterns['hourly_distribution']);
        for ($hour = 0; $hour < 24; $hour++) {
            if ($patterns['hourly_distribution'][$hour] > $maxHourlyUsage * 0.8) {
                $patterns['peak_hours'][] = $hour;
            }
        }
        
        return $patterns;
    }
    
    private function clearUsageCaches(string $tenantId): void
    {
        $patterns = [
            "cost_attribution_{$tenantId}_*",
            "optimization_recommendations_{$tenantId}",
            "cost_forecast_{$tenantId}_*",
            "usage_analytics_{$tenantId}_*"
        ];
        
        foreach ($patterns as $pattern) {
            // Note: This is a simplified cache clearing
            // In production, you might need a more sophisticated approach
            Cache::forget($pattern);
        }
    }
    
    private function calculateTrend(array $data): float
    {
        // Simple linear regression for trend calculation
        $count = count($data);
        if ($count < 2) return 0;
        
        $sumX = array_sum(range(0, $count - 1));
        $sumY = array_sum($data);
        $sumXY = 0;
        $sumXX = 0;
        
        foreach ($data as $i => $value) {
            $sumXY += $i * $value;
            $sumXX += $i * $i;
        }
        
        $slope = ($count * $sumXY - $sumX * $sumY) / ($count * $sumXX - $sumX * $sumX);
        return $slope / ($sumY / $count); // Normalized slope
    }
    
    private function calculateSeasonality(array $data): array
    {
        // Simple weekly seasonality calculation
        $weeklyPattern = array_fill(0, 7, 1);
        
        // This would be more sophisticated in production
        // For now, return uniform distribution
        return $weeklyPattern;
    }
    
    private function getBaseCostForResource(string $resourceType, string $tenantId): float
    {
        $avgCost = TenantResourceUsage::forTenant($tenantId)
            ->forResourceType($resourceType)
            ->where('usage_date', '>=', Carbon::now()->subDays(30))
            ->avg('total_cost');
        
        return $avgCost ?: 1.0;
    }
    
    private function calculateForecastConfidence(array $data): float
    {
        // Calculate confidence based on data consistency
        if (count($data) < 7) return 0.3;
        
        $variance = $this->calculateVariance($data);
        $mean = array_sum($data) / count($data);
        
        $coefficientOfVariation = $mean > 0 ? $variance / $mean : 1;
        
        return max(0.3, min(0.95, 1 - $coefficientOfVariation));
    }
    
    private function calculateVariance(array $data): float
    {
        $mean = array_sum($data) / count($data);
        $variance = array_sum(array_map(function($x) use ($mean) {
            return pow($x - $mean, 2);
        }, $data)) / count($data);
        
        return $variance;
    }
    
    private function calculateOverallGrowthRate(array $historicalData): float
    {
        // Calculate overall growth rate across all resource types
        $totalGrowth = 0;
        $resourceCount = 0;
        
        foreach ($historicalData['by_resource_type'] as $data) {
            $totalGrowth += $this->calculateTrend($data);
            $resourceCount++;
        }
        
        return $resourceCount > 0 ? $totalGrowth / $resourceCount : 0;
    }
    
    private function calculateOverallConfidence(array $forecast): float
    {
        $confidenceScores = array_column($forecast, 'confidence');
        return count($confidenceScores) > 0 ? array_sum($confidenceScores) / count($confidenceScores) : 0.5;
    }
    
    private function calculateErrorCostImpact(TenantResourceUsage $resource): float
    {
        // Estimate cost impact of errors
        $errorCost = $resource->total_errors * $resource->cost_per_unit * 0.1; // 10% overhead per error
        return round($errorCost, 2);
    }
    
    private function findUnderutilizedHours(string $tenantId): array
    {
        $patterns = $this->analyzeUsagePatterns($tenantId);
        $avgUsage = array_sum($patterns['hourly_distribution']) / 24;
        
        $underutilized = [];
        for ($hour = 0; $hour < 24; $hour++) {
            if ($patterns['hourly_distribution'][$hour] < $avgUsage * 0.5) {
                $underutilized[] = $hour;
            }
        }
        
        return $underutilized;
    }
    
    private function identifyOversizedResources(string $tenantId): array
    {
        // This would analyze resource utilization and recommend right-sizing
        // Simplified implementation
        return [];
    }
    
    private function calculateSchedulingOptimization(string $tenantId): float
    {
        // Calculate potential savings from scheduling optimization
        return 50.0; // Placeholder
    }
    
    private function identifyBulkProcessingOpportunities(string $tenantId): array
    {
        // Analyze for batch processing opportunities
        return [
            'count' => 0,
            'potential_savings' => 0
        ];
    }
    
    private function getHistoricalUsageData(string $tenantId, int $days): array
    {
        return [
            'by_resource_type' => []
        ];
    }
}