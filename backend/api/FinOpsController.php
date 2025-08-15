<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FinOpsService;
use App\Models\TenantBudget;
use App\Models\BudgetAlert;
use App\Models\TenantResourceUsage;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class FinOpsController extends Controller
{
    protected FinOpsService $finOpsService;

    public function __construct(FinOpsService $finOpsService)
    {
        $this->finOpsService = $finOpsService;
    }

    /**
     * Get comprehensive dashboard overview
     */
    public function getDashboard(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID');
        
        try {
            $dashboard = [
                'summary' => $this->getDashboardSummary($tenantId),
                'budgets' => $this->getBudgetOverview($tenantId),
                'alerts' => $this->getActiveAlerts($tenantId),
                'cost_trends' => $this->getCostTrends($tenantId),
                'top_resources' => $this->getTopResourceConsumers($tenantId),
                'recommendations' => $this->finOpsService->generateOptimizationRecommendations($tenantId),
                'forecast' => $this->getCostForecast($tenantId)
            ];

            return response()->json([
                'success' => true,
                'data' => $dashboard,
                'generated_at' => Carbon::now()->toISOString()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to load dashboard',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detailed cost attribution analysis
     */
    public function getCostAttribution(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'group_by' => 'in:user,department,resource_type,resource'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');
        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        try {
            $attribution = $this->finOpsService->calculateCostAttribution($tenantId, $startDate, $endDate);
            
            // Add additional analytics
            $attribution['analytics'] = [
                'period_summary' => [
                    'days' => $startDate->diffInDays($endDate) + 1,
                    'average_daily_cost' => $attribution['total_cost'] / ($startDate->diffInDays($endDate) + 1),
                    'cost_velocity' => $this->calculateCostVelocity($attribution['cost_trends']),
                    'top_cost_driver' => $this->getTopCostDriver($attribution)
                ],
                'efficiency_metrics' => $this->getEfficiencyMetrics($tenantId, $startDate, $endDate),
                'comparative_analysis' => $this->getComparativeAnalysis($tenantId, $startDate, $endDate)
            ];

            return response()->json([
                'success' => true,
                'data' => $attribution
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to calculate cost attribution',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detailed usage analytics
     */
    public function getUsageAnalytics(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'resource_type' => 'string|nullable',
            'include_patterns' => 'boolean',
            'include_forecasting' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');
        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        try {
            $analytics = $this->finOpsService->getUsageAnalytics($tenantId, $startDate, $endDate);
            
            // Add resource-specific analysis if requested
            if ($request->resource_type) {
                $analytics['resource_specific'] = $this->getResourceSpecificAnalytics(
                    $tenantId, 
                    $request->resource_type, 
                    $startDate, 
                    $endDate
                );
            }

            // Add pattern analysis if requested
            if ($request->boolean('include_patterns', true)) {
                $analytics['usage_patterns'] = $this->getUsagePatterns($tenantId, $startDate, $endDate);
            }

            // Add forecasting if requested
            if ($request->boolean('include_forecasting', false)) {
                $analytics['forecasting'] = $this->finOpsService->generateCostForecast($tenantId, 30);
            }

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to generate usage analytics',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Record usage with attribution
     */
    public function recordUsage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'resource_type' => 'required|string',
            'amount' => 'required|numeric|min:0',
            'user_id' => 'required|string',
            'resource_id' => 'string|nullable',
            'department' => 'string|nullable',
            'metrics' => 'array|nullable'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');

        try {
            $result = $this->finOpsService->recordUsageWithAttribution(
                $tenantId,
                $request->user_id,
                $request->resource_type,
                [
                    'amount' => $request->amount,
                    'resource_id' => $request->resource_id,
                    'department' => $request->department,
                    'metrics' => $request->metrics ?? []
                ]
            );

            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to record usage',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Budget management endpoints
     */
    public function getBudgets(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID');
        
        try {
            $budgets = TenantBudget::forTenant($tenantId)
                ->with(['alerts' => function ($query) {
                    $query->active()->latest('triggered_at')->limit(5);
                }])
                ->orderBy('budget_name')
                ->get();

            $summary = [
                'total_budgets' => $budgets->count(),
                'total_budget_amount' => $budgets->sum('budget_amount'),
                'total_spent' => $budgets->sum('spent_amount'),
                'total_remaining' => $budgets->sum('remaining_amount'),
                'average_utilization' => $budgets->avg('utilization_percentage'),
                'budgets_over_threshold' => $budgets->filter(fn($b) => $b->getUtilizationPercentage() > 80)->count()
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'budgets' => $budgets,
                    'summary' => $summary
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve budgets',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function createBudget(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'budget_name' => 'required|string|max:255',
            'budget_type' => 'required|in:monthly,quarterly,annual,custom',
            'scope' => 'required|in:tenant,department,user,resource_type',
            'scope_value' => 'string|nullable',
            'budget_amount' => 'required|numeric|min:0',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'alert_thresholds' => 'array|nullable',
            'alert_recipients' => 'array|nullable',
            'enforce_hard_limit' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');

        try {
            $budgetData = $request->validated();
            $budgetData['tenant_id'] = $tenantId;
            $budgetData['created_by'] = $request->user()->id ?? 'api';

            $budget = TenantBudget::createBudget($budgetData);

            return response()->json([
                'success' => true,
                'data' => $budget,
                'message' => 'Budget created successfully'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to create budget',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function updateBudget(Request $request, string $budgetId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID');
        
        try {
            $budget = TenantBudget::forTenant($tenantId)->findOrFail($budgetId);
            
            $validator = Validator::make($request->all(), [
                'budget_name' => 'string|max:255',
                'budget_amount' => 'numeric|min:0',
                'alert_thresholds' => 'array',
                'alert_recipients' => 'array',
                'enforce_hard_limit' => 'boolean',
                'status' => 'in:active,suspended,expired,draft'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $budget->update($request->validated());

            return response()->json([
                'success' => true,
                'data' => $budget,
                'message' => 'Budget updated successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to update budget',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Alert management endpoints
     */
    public function getAlerts(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID');
        $status = $request->query('status', 'active');
        $severity = $request->query('severity');
        $limit = $request->query('limit', 50);

        try {
            $query = BudgetAlert::forTenant($tenantId)
                ->with(['budget:id,budget_name'])
                ->orderBy('severity', 'desc')
                ->orderBy('triggered_at', 'desc');

            if ($status !== 'all') {
                $query->where('status', $status);
            }

            if ($severity) {
                $query->where('severity', $severity);
            }

            $alerts = $query->limit($limit)->get();
            $dashboardData = BudgetAlert::getAlertsForDashboard($tenantId);

            return response()->json([
                'success' => true,
                'data' => [
                    'alerts' => $alerts,
                    'dashboard' => $dashboardData,
                    'trends' => BudgetAlert::getAlertTrends($tenantId, 30)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve alerts',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function acknowledgeAlert(Request $request, string $alertId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID');
        
        try {
            $alert = BudgetAlert::forTenant($tenantId)->findOrFail($alertId);
            
            $alert->acknowledge(
                $request->user()->id ?? 'api',
                $request->input('notes')
            );

            return response()->json([
                'success' => true,
                'data' => $alert,
                'message' => 'Alert acknowledged successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to acknowledge alert',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function resolveAlert(Request $request, string $alertId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID');
        
        try {
            $alert = BudgetAlert::forTenant($tenantId)->findOrFail($alertId);
            
            $alert->resolve(
                $request->user()->id ?? 'api',
                $request->input('resolution_notes')
            );

            return response()->json([
                'success' => true,
                'data' => $alert,
                'message' => 'Alert resolved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to resolve alert',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Optimization recommendations
     */
    public function getOptimizationRecommendations(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID');
        
        try {
            $recommendations = $this->finOpsService->generateOptimizationRecommendations($tenantId);
            
            return response()->json([
                'success' => true,
                'data' => $recommendations
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to generate recommendations',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cost forecasting
     */
    public function getCostForecast(Request $request, int $days = 30): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID');
        
        try {
            $forecast = $this->finOpsService->generateCostForecast($tenantId, $days);
            
            return response()->json([
                'success' => true,
                'data' => $forecast
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to generate forecast',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export functionality
     */
    public function exportCostReport(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'format' => 'in:json,csv,xlsx',
            'include_details' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');
        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        try {
            $reportData = [
                'period' => [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'days' => $startDate->diffInDays($endDate) + 1
                ],
                'tenant' => Tenant::find($tenantId, ['id', 'name', 'tier']),
                'cost_attribution' => $this->finOpsService->calculateCostAttribution($tenantId, $startDate, $endDate),
                'usage_analytics' => $this->finOpsService->getUsageAnalytics($tenantId, $startDate, $endDate),
                'budgets' => TenantBudget::forTenant($tenantId)->currentPeriod()->get(),
                'alerts' => BudgetAlert::forTenant($tenantId)
                    ->whereBetween('triggered_at', [$startDate, $endDate])
                    ->get(),
                'generated_at' => Carbon::now()->toISOString()
            ];

            // Add detailed breakdown if requested
            if ($request->boolean('include_details', false)) {
                $reportData['detailed_usage'] = TenantResourceUsage::forTenant($tenantId)
                    ->forDateRange($startDate, $endDate)
                    ->orderBy('usage_date')
                    ->get();
            }

            return response()->json([
                'success' => true,
                'data' => $reportData,
                'export_format' => $request->input('format', 'json')
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to generate cost report',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Private helper methods

    private function getDashboardSummary(string $tenantId): array
    {
        $currentMonth = Carbon::now();
        $previousMonth = Carbon::now()->subMonth();

        $currentCost = TenantResourceUsage::getTenantMonthlyCost($tenantId, $currentMonth);
        $previousCost = TenantResourceUsage::getTenantMonthlyCost($tenantId, $previousMonth);

        $budgets = TenantBudget::getActiveBudgetsForTenant($tenantId);
        $totalBudget = $budgets->sum('budget_amount');
        $totalSpent = $budgets->sum('spent_amount');

        return [
            'total_cost_this_month' => $currentCost,
            'total_cost_last_month' => $previousCost,
            'cost_change_percentage' => $previousCost > 0 ? (($currentCost - $previousCost) / $previousCost) * 100 : 0,
            'total_budget' => $totalBudget,
            'total_spent' => $totalSpent,
            'budget_utilization' => $totalBudget > 0 ? ($totalSpent / $totalBudget) * 100 : 0,
            'active_budgets' => $budgets->count(),
            'budgets_over_threshold' => $budgets->filter(fn($b) => $b->getUtilizationPercentage() > 80)->count()
        ];
    }

    private function getBudgetOverview(string $tenantId): array
    {
        $budgets = TenantBudget::getActiveBudgetsForTenant($tenantId);
        
        return [
            'budgets' => $budgets->take(5), // Top 5 for dashboard
            'summary' => [
                'total_count' => $budgets->count(),
                'total_amount' => $budgets->sum('budget_amount'),
                'total_spent' => $budgets->sum('spent_amount'),
                'average_utilization' => $budgets->avg('utilization_percentage'),
                'budgets_needing_attention' => TenantBudget::getBudgetsNeedingAttention($tenantId)->count()
            ]
        ];
    }

    private function getActiveAlerts(string $tenantId): array
    {
        return BudgetAlert::getAlertsForDashboard($tenantId);
    }

    private function getCostTrends(string $tenantId, int $days = 30): array
    {
        $trends = [];
        $startDate = Carbon::now()->subDays($days);
        
        for ($i = 0; $i < $days; $i++) {
            $date = $startDate->copy()->addDays($i);
            $dailyCost = TenantResourceUsage::forTenant($tenantId)
                ->where('usage_date', $date)
                ->sum('total_cost');
            
            $trends[] = [
                'date' => $date->format('Y-m-d'),
                'cost' => $dailyCost
            ];
        }
        
        return $trends;
    }

    private function getTopResourceConsumers(string $tenantId, int $limit = 5): array
    {
        return TenantResourceUsage::forTenant($tenantId)
            ->currentMonth()
            ->selectRaw('resource_type, SUM(total_cost) as total_cost, SUM(total_usage) as total_usage')
            ->groupBy('resource_type')
            ->orderByDesc('total_cost')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    private function calculateCostVelocity(array $costTrends): float
    {
        if (count($costTrends) < 2) {
            return 0;
        }
        
        $values = array_values($costTrends);
        $recent = array_slice($values, -7); // Last 7 days
        $previous = array_slice($values, -14, 7); // Previous 7 days
        
        $recentAvg = array_sum($recent) / count($recent);
        $previousAvg = array_sum($previous) / count($previous);
        
        return $previousAvg > 0 ? (($recentAvg - $previousAvg) / $previousAvg) * 100 : 0;
    }

    private function getTopCostDriver(array $attribution): array
    {
        $maxCost = 0;
        $topDriver = null;
        
        foreach ($attribution['by_resource_type'] as $resourceType => $cost) {
            if ($cost > $maxCost) {
                $maxCost = $cost;
                $topDriver = $resourceType;
            }
        }
        
        return [
            'resource_type' => $topDriver,
            'cost' => $maxCost,
            'percentage' => $attribution['total_cost'] > 0 ? ($maxCost / $attribution['total_cost']) * 100 : 0
        ];
    }

    private function getEfficiencyMetrics(string $tenantId, Carbon $startDate, Carbon $endDate): array
    {
        $records = TenantResourceUsage::forTenant($tenantId)
            ->forDateRange($startDate, $endDate)
            ->get();
        
        return [
            'average_efficiency_score' => $records->map->getEfficiencyScore()->avg(),
            'total_errors' => $records->sum('total_errors'),
            'error_rate' => $records->sum('total_usage') > 0 ? ($records->sum('total_errors') / $records->sum('total_usage')) * 100 : 0,
            'average_response_time' => $records->avg('average_response_time_ms')
        ];
    }

    private function getComparativeAnalysis(string $tenantId, Carbon $startDate, Carbon $endDate): array
    {
        // Compare with previous period
        $periodDays = $startDate->diffInDays($endDate) + 1;
        $prevStartDate = $startDate->copy()->subDays($periodDays);
        $prevEndDate = $startDate->copy()->subDay();
        
        $currentCost = TenantResourceUsage::forTenant($tenantId)
            ->forDateRange($startDate, $endDate)
            ->sum('total_cost');
            
        $previousCost = TenantResourceUsage::forTenant($tenantId)
            ->forDateRange($prevStartDate, $prevEndDate)
            ->sum('total_cost');
        
        return [
            'current_period_cost' => $currentCost,
            'previous_period_cost' => $previousCost,
            'change_amount' => $currentCost - $previousCost,
            'change_percentage' => $previousCost > 0 ? (($currentCost - $previousCost) / $previousCost) * 100 : 0,
            'trend' => $currentCost > $previousCost ? 'increasing' : 'decreasing'
        ];
    }

    private function getResourceSpecificAnalytics(string $tenantId, string $resourceType, Carbon $startDate, Carbon $endDate): array
    {
        $records = TenantResourceUsage::forTenant($tenantId)
            ->forResourceType($resourceType)
            ->forDateRange($startDate, $endDate)
            ->get();
        
        return [
            'total_usage' => $records->sum('total_usage'),
            'total_cost' => $records->sum('total_cost'),
            'average_efficiency' => $records->map->getEfficiencyScore()->avg(),
            'peak_usage' => $records->max('peak_usage'),
            'error_rate' => $records->sum('total_usage') > 0 ? ($records->sum('total_errors') / $records->sum('total_usage')) * 100 : 0,
            'daily_breakdown' => $records->map(function ($record) {
                return [
                    'date' => $record->usage_date->format('Y-m-d'),
                    'usage' => $record->total_usage,
                    'cost' => $record->total_cost,
                    'efficiency' => $record->getEfficiencyScore()
                ];
            })->toArray()
        ];
    }

    private function getUsagePatterns(string $tenantId, Carbon $startDate, Carbon $endDate): array
    {
        // Simplified usage pattern analysis
        $records = TenantResourceUsage::forTenant($tenantId)
            ->forDateRange($startDate, $endDate)
            ->get();
        
        $hourlyPattern = array_fill(0, 24, 0);
        $dailyPattern = array_fill(0, 7, 0);
        
        foreach ($records as $record) {
            $hourlyBreakdown = $record->hourly_breakdown ?? [];
            for ($hour = 0; $hour < 24; $hour++) {
                $hourlyPattern[$hour] += $hourlyBreakdown[$hour] ?? 0;
            }
            
            $dayOfWeek = $record->usage_date->dayOfWeek;
            $dailyPattern[$dayOfWeek] += $record->total_usage;
        }
        
        return [
            'hourly_pattern' => $hourlyPattern,
            'daily_pattern' => $dailyPattern,
            'peak_hour' => array_keys($hourlyPattern, max($hourlyPattern))[0] ?? 0,
            'peak_day' => array_keys($dailyPattern, max($dailyPattern))[0] ?? 0
        ];
    }
}