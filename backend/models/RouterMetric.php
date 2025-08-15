<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RouterMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'run_id',
        'algorithm',
        'budget_per_role',
        'selected_documents',
        'importance_scores',
        'token_savings_percentage',
        'selection_time_ms',
        'total_selected_tokens',
        'efficiency_gain',
        'metadata'
    ];

    protected $casts = [
        'budget_per_role' => 'array',
        'selected_documents' => 'array',
        'importance_scores' => 'array',
        'token_savings_percentage' => 'float',
        'selection_time_ms' => 'integer',
        'total_selected_tokens' => 'integer',
        'efficiency_gain' => 'float',
        'metadata' => 'array'
    ];

    /**
     * Get the orchestration run this metric belongs to.
     */
    public function orchestrationRun(): BelongsTo
    {
        return $this->belongsTo(OrchestrationRun::class, 'run_id', 'run_id');
    }

    /**
     * Scope for efficient routes (high token savings).
     */
    public function scopeEfficient($query, $threshold = 30)
    {
        return $query->where('token_savings_percentage', '>=', $threshold);
    }

    /**
     * Scope for fast routing decisions.
     */
    public function scopeFast($query, $maxMs = 100)
    {
        return $query->where('selection_time_ms', '<=', $maxMs);
    }

    /**
     * Get average token savings.
     */
    public static function averageTokenSavings()
    {
        return self::avg('token_savings_percentage');
    }

    /**
     * Get average selection time.
     */
    public static function averageSelectionTime()
    {
        return self::avg('selection_time_ms');
    }

    /**
     * Get metrics by algorithm.
     */
    public static function getMetricsByAlgorithm($algorithm = 'RCR')
    {
        return [
            'avg_savings' => self::where('algorithm', $algorithm)->avg('token_savings_percentage'),
            'avg_time' => self::where('algorithm', $algorithm)->avg('selection_time_ms'),
            'avg_efficiency' => self::where('algorithm', $algorithm)->avg('efficiency_gain'),
            'total_runs' => self::where('algorithm', $algorithm)->count()
        ];
    }

    /**
     * Calculate actual efficiency gain.
     */
    public function calculateEfficiencyGain()
    {
        if ($this->token_savings_percentage > 0) {
            return $this->token_savings_percentage / 100;
        }
        return 0;
    }

    /**
     * Get role with highest budget.
     */
    public function getHighestBudgetRole()
    {
        if (!is_array($this->budget_per_role)) {
            return null;
        }

        $maxBudget = 0;
        $maxRole = null;

        foreach ($this->budget_per_role as $role => $budget) {
            if ($budget > $maxBudget) {
                $maxBudget = $budget;
                $maxRole = $role;
            }
        }

        return ['role' => $maxRole, 'budget' => $maxBudget];
    }

    /**
     * Get total documents selected.
     */
    public function getTotalDocumentsSelected()
    {
        if (!is_array($this->selected_documents)) {
            return 0;
        }

        $total = 0;
        foreach ($this->selected_documents as $role => $docs) {
            $total += count($docs);
        }

        return $total;
    }
}