<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrchestrationRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'run_id',
        'correlation_id',
        'workflow_id',
        'query',
        'status',
        'success',
        'answer',
        'terminator_reason',
        'stability_score',
        'total_tokens',
        'total_duration_ms',
        'error_message',
        'metadata',
        'artifacts',
        'started_at',
        'completed_at'
    ];

    protected $casts = [
        'success' => 'boolean',
        'stability_score' => 'float',
        'total_tokens' => 'integer',
        'total_duration_ms' => 'integer',
        'metadata' => 'array',
        'artifacts' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime'
    ];

    /**
     * Get the tenant that owns this orchestration run.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the agent executions for this run.
     */
    public function agentExecutions(): HasMany
    {
        return $this->hasMany(AgentExecution::class);
    }

    /**
     * Get the router metrics for this run.
     */
    public function routerMetrics(): HasMany
    {
        return $this->hasMany(RouterMetric::class, 'run_id', 'run_id');
    }

    /**
     * Scope for successful runs.
     */
    public function scopeSuccessful($query)
    {
        return $query->where('success', true);
    }

    /**
     * Scope for failed runs.
     */
    public function scopeFailed($query)
    {
        return $query->where('success', false);
    }

    /**
     * Scope for runs in a specific status.
     */
    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope for runs belonging to a specific tenant.
     */
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Get average stability score.
     */
    public static function averageStabilityScore()
    {
        return self::whereNotNull('stability_score')->avg('stability_score');
    }

    /**
     * Get success rate.
     */
    public static function successRate()
    {
        $total = self::count();
        if ($total === 0) return 0;
        
        $successful = self::successful()->count();
        return ($successful / $total) * 100;
    }

    /**
     * Mark run as completed.
     */
    public function markCompleted($success = true, $answer = null, $error = null)
    {
        $this->update([
            'status' => $success ? 'completed' : 'failed',
            'success' => $success,
            'answer' => $answer,
            'error_message' => $error,
            'completed_at' => now()
        ]);
    }
}