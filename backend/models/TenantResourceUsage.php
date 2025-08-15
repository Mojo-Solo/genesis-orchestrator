<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Carbon\Carbon;

class TenantResourceUsage extends Model
{
    use HasUuids;

    protected $table = 'tenant_resource_usage';

    protected $fillable = [
        'tenant_id',
        'usage_date',
        'resource_type',
        'total_usage',
        'peak_usage',
        'average_usage',
        'unique_operations',
        'average_response_time_ms',
        'p95_response_time_ms',
        'error_rate_percent',
        'total_errors',
        'cost_per_unit',
        'total_cost',
        'cost_currency',
        'detailed_metrics',
        'hourly_breakdown',
        'billed',
        'invoice_id',
        'billed_at'
    ];

    protected $casts = [
        'usage_date' => 'date',
        'total_usage' => 'integer',
        'peak_usage' => 'integer',
        'average_usage' => 'integer',
        'unique_operations' => 'integer',
        'average_response_time_ms' => 'decimal:2',
        'p95_response_time_ms' => 'decimal:2',
        'error_rate_percent' => 'decimal:2',
        'total_errors' => 'integer',
        'cost_per_unit' => 'decimal:6',
        'total_cost' => 'decimal:2',
        'detailed_metrics' => 'array',
        'hourly_breakdown' => 'array',
        'billed' => 'boolean',
        'billed_at' => 'datetime'
    ];

    protected $dates = [
        'usage_date',
        'billed_at',
        'created_at',
        'updated_at'
    ];

    // Resource type constants
    const RESOURCE_ORCHESTRATION_RUNS = 'orchestration_runs';
    const RESOURCE_API_CALLS = 'api_calls';
    const RESOURCE_TOKENS = 'tokens';
    const RESOURCE_STORAGE = 'storage';
    const RESOURCE_BANDWIDTH = 'bandwidth';
    const RESOURCE_AGENT_EXECUTIONS = 'agent_executions';
    const RESOURCE_MEMORY_ITEMS = 'memory_items';
    const RESOURCE_ROUTER_CALLS = 'router_calls';

    // Cost calculation constants
    const COST_PER_ORCHESTRATION_RUN = 0.10;  // $0.10 per run
    const COST_PER_1K_API_CALLS = 1.00;       // $1.00 per 1000 API calls
    const COST_PER_1K_TOKENS = 0.002;         // $0.002 per 1000 tokens
    const COST_PER_GB_STORAGE = 0.50;         // $0.50 per GB per month
    const COST_PER_GB_BANDWIDTH = 0.12;       // $0.12 per GB bandwidth
    const COST_PER_AGENT_EXECUTION = 0.05;    // $0.05 per execution

    // Relationships
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // Scopes
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForResourceType($query, $resourceType)
    {
        return $query->where('resource_type', $resourceType);
    }

    public function scopeForDateRange($query, Carbon $startDate, Carbon $endDate)
    {
        return $query->whereBetween('usage_date', [$startDate, $endDate]);
    }

    public function scopeCurrentMonth($query)
    {
        return $query->whereBetween('usage_date', [
            Carbon::now()->startOfMonth(),
            Carbon::now()->endOfMonth()
        ]);
    }

    public function scopeLastMonth($query)
    {
        return $query->whereBetween('usage_date', [
            Carbon::now()->subMonth()->startOfMonth(),
            Carbon::now()->subMonth()->endOfMonth()
        ]);
    }

    public function scopeUnbilled($query)
    {
        return $query->where('billed', false);
    }

    public function scopeBilled($query)
    {
        return $query->where('billed', true);
    }

    public function scopeHighUsage($query, $threshold = 1000)
    {
        return $query->where('total_usage', '>', $threshold);
    }

    public function scopeWithErrors($query)
    {
        return $query->where('total_errors', '>', 0);
    }

    // Usage Recording Methods
    public static function recordUsage(
        string $tenantId,
        string $resourceType,
        int $usage = 1,
        array $metrics = [],
        Carbon $date = null
    ): self {
        $date = $date ?: Carbon::today();

        $record = static::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'usage_date' => $date,
                'resource_type' => $resourceType
            ],
            [
                'total_usage' => 0,
                'peak_usage' => 0,
                'average_usage' => 0,
                'unique_operations' => 0,
                'total_errors' => 0,
                'error_rate_percent' => 0,
                'cost_per_unit' => static::getCostPerUnit($resourceType),
                'cost_currency' => 'USD',
                'detailed_metrics' => [],
                'hourly_breakdown' => array_fill(0, 24, 0)
            ]
        );

        $record->incrementUsage($usage, $metrics);
        
        return $record;
    }

    public function incrementUsage(int $usage, array $metrics = []): void
    {
        $currentHour = Carbon::now()->hour;
        $hourlyBreakdown = $this->hourly_breakdown ?? array_fill(0, 24, 0);
        $hourlyBreakdown[$currentHour] += $usage;

        $this->total_usage += $usage;
        $this->peak_usage = max($this->peak_usage, $usage);
        $this->average_usage = $this->total_usage; // Will be recalculated properly
        $this->hourly_breakdown = $hourlyBreakdown;

        // Update performance metrics if provided
        if (isset($metrics['response_time_ms'])) {
            $this->updateResponseTimeMetrics($metrics['response_time_ms']);
        }

        if (isset($metrics['error'])) {
            $this->total_errors++;
            $this->error_rate_percent = ($this->total_errors / $this->total_usage) * 100;
        }

        // Update detailed metrics
        $detailedMetrics = $this->detailed_metrics ?? [];
        foreach ($metrics as $key => $value) {
            if ($key !== 'response_time_ms' && $key !== 'error') {
                $detailedMetrics[$key] = ($detailedMetrics[$key] ?? 0) + $value;
            }
        }
        $this->detailed_metrics = $detailedMetrics;

        // Recalculate total cost
        $this->total_cost = $this->calculateTotalCost();

        $this->save();
    }

    private function updateResponseTimeMetrics(float $responseTime): void
    {
        // Simple running average calculation
        // In production, you might want to use a more sophisticated approach
        if ($this->average_response_time_ms === null) {
            $this->average_response_time_ms = $responseTime;
            $this->p95_response_time_ms = $responseTime;
        } else {
            // Update running average
            $this->average_response_time_ms = 
                (($this->average_response_time_ms * ($this->total_usage - 1)) + $responseTime) 
                / $this->total_usage;

            // Simple P95 approximation (you'd want a more accurate implementation)
            $this->p95_response_time_ms = max($this->p95_response_time_ms, $responseTime);
        }
    }

    // Cost Calculation Methods
    public static function getCostPerUnit(string $resourceType): float
    {
        $costs = [
            self::RESOURCE_ORCHESTRATION_RUNS => self::COST_PER_ORCHESTRATION_RUN,
            self::RESOURCE_API_CALLS => self::COST_PER_1K_API_CALLS / 1000,
            self::RESOURCE_TOKENS => self::COST_PER_1K_TOKENS / 1000,
            self::RESOURCE_STORAGE => self::COST_PER_GB_STORAGE,
            self::RESOURCE_BANDWIDTH => self::COST_PER_GB_BANDWIDTH,
            self::RESOURCE_AGENT_EXECUTIONS => self::COST_PER_AGENT_EXECUTION,
            self::RESOURCE_MEMORY_ITEMS => 0.001, // $0.001 per memory item
            self::RESOURCE_ROUTER_CALLS => 0.0001 // $0.0001 per router call
        ];

        return $costs[$resourceType] ?? 0.001;
    }

    public function calculateTotalCost(): float
    {
        return round($this->total_usage * $this->cost_per_unit, 2);
    }

    // Analytics Methods
    public function getUsageGrowth(): float
    {
        $previousDay = static::where('tenant_id', $this->tenant_id)
            ->where('resource_type', $this->resource_type)
            ->where('usage_date', $this->usage_date->subDay())
            ->first();

        if (!$previousDay || $previousDay->total_usage === 0) {
            return 0;
        }

        return (($this->total_usage - $previousDay->total_usage) / $previousDay->total_usage) * 100;
    }

    public function getEfficiencyScore(): float
    {
        if ($this->total_usage === 0) {
            return 100;
        }

        // Efficiency score based on error rate and response time
        $errorPenalty = $this->error_rate_percent * 2; // 2 points per % error rate
        $responsePenalty = 0;

        if ($this->average_response_time_ms > 1000) {
            $responsePenalty = min(20, ($this->average_response_time_ms - 1000) / 100);
        }

        return max(0, 100 - $errorPenalty - $responsePenalty);
    }

    public function getPeakHour(): int
    {
        $hourlyBreakdown = $this->hourly_breakdown ?? [];
        $maxUsage = max($hourlyBreakdown);
        
        return array_search($maxUsage, $hourlyBreakdown) ?: 0;
    }

    public function getUsageDistribution(): array
    {
        $hourlyBreakdown = $this->hourly_breakdown ?? array_fill(0, 24, 0);
        $total = array_sum($hourlyBreakdown);

        if ($total === 0) {
            return array_fill(0, 24, 0);
        }

        return array_map(function ($usage) use ($total) {
            return round(($usage / $total) * 100, 2);
        }, $hourlyBreakdown);
    }

    // Billing Methods
    public function markAsBilled(string $invoiceId = null): void
    {
        $this->update([
            'billed' => true,
            'invoice_id' => $invoiceId,
            'billed_at' => Carbon::now()
        ]);
    }

    public function markAsUnbilled(): void
    {
        $this->update([
            'billed' => false,
            'invoice_id' => null,
            'billed_at' => null
        ]);
    }

    // Static Analysis Methods
    public static function getTenantMonthlyCost(string $tenantId, Carbon $month = null): float
    {
        $month = $month ?: Carbon::now();
        
        return static::where('tenant_id', $tenantId)
            ->whereBetween('usage_date', [
                $month->startOfMonth(),
                $month->endOfMonth()
            ])
            ->sum('total_cost');
    }

    public static function getTenantResourceBreakdown(string $tenantId, Carbon $month = null): array
    {
        $month = $month ?: Carbon::now();
        
        return static::where('tenant_id', $tenantId)
            ->whereBetween('usage_date', [
                $month->startOfMonth(),
                $month->endOfMonth()
            ])
            ->selectRaw('resource_type, SUM(total_usage) as total_usage, SUM(total_cost) as total_cost')
            ->groupBy('resource_type')
            ->get()
            ->pluck('total_cost', 'resource_type')
            ->toArray();
    }

    public static function getTopResourceConsumers(string $resourceType, int $limit = 10): array
    {
        return static::where('resource_type', $resourceType)
            ->currentMonth()
            ->selectRaw('tenant_id, SUM(total_usage) as total_usage, SUM(total_cost) as total_cost')
            ->groupBy('tenant_id')
            ->orderByDesc('total_usage')
            ->limit($limit)
            ->with('tenant:id,name,tier')
            ->get()
            ->toArray();
    }

    public static function getResourceTrends(string $resourceType, int $days = 30): array
    {
        return static::where('resource_type', $resourceType)
            ->where('usage_date', '>=', Carbon::now()->subDays($days))
            ->selectRaw('usage_date, SUM(total_usage) as total_usage')
            ->groupBy('usage_date')
            ->orderBy('usage_date')
            ->get()
            ->pluck('total_usage', 'usage_date')
            ->toArray();
    }

    // Utility Methods
    public function getFormattedCost(): string
    {
        return '$' . number_format($this->total_cost, 2);
    }

    public function getFormattedUsage(): string
    {
        $units = [
            self::RESOURCE_ORCHESTRATION_RUNS => 'runs',
            self::RESOURCE_API_CALLS => 'calls',
            self::RESOURCE_TOKENS => 'tokens',
            self::RESOURCE_STORAGE => 'GB',
            self::RESOURCE_BANDWIDTH => 'GB',
            self::RESOURCE_AGENT_EXECUTIONS => 'executions',
            self::RESOURCE_MEMORY_ITEMS => 'items',
            self::RESOURCE_ROUTER_CALLS => 'calls'
        ];

        $unit = $units[$this->resource_type] ?? 'units';
        
        return number_format($this->total_usage) . ' ' . $unit;
    }

    public function getResourceTypeDisplayName(): string
    {
        $names = [
            self::RESOURCE_ORCHESTRATION_RUNS => 'Orchestration Runs',
            self::RESOURCE_API_CALLS => 'API Calls',
            self::RESOURCE_TOKENS => 'Tokens',
            self::RESOURCE_STORAGE => 'Storage',
            self::RESOURCE_BANDWIDTH => 'Bandwidth',
            self::RESOURCE_AGENT_EXECUTIONS => 'Agent Executions',
            self::RESOURCE_MEMORY_ITEMS => 'Memory Items',
            self::RESOURCE_ROUTER_CALLS => 'Router Calls'
        ];

        return $names[$this->resource_type] ?? ucwords(str_replace('_', ' ', $this->resource_type));
    }
}