<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AgentExecution extends Model
{
    use HasFactory;

    protected $fillable = [
        'orchestration_run_id',
        'agent_id',
        'capability',
        'sequence_number',
        'status',
        'input_context',
        'output_context',
        'duration_ms',
        'tokens_used',
        'error_message',
        'retry_count',
        'metadata',
        'started_at',
        'completed_at'
    ];

    protected $casts = [
        'sequence_number' => 'integer',
        'duration_ms' => 'integer',
        'tokens_used' => 'integer',
        'retry_count' => 'integer',
        'input_context' => 'array',
        'output_context' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime'
    ];

    /**
     * Get the orchestration run this execution belongs to.
     */
    public function orchestrationRun(): BelongsTo
    {
        return $this->belongsTo(OrchestrationRun::class);
    }

    /**
     * Scope for successful executions.
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope for failed executions.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope for executions by agent.
     */
    public function scopeByAgent($query, $agentId)
    {
        return $query->where('agent_id', $agentId);
    }

    /**
     * Get average duration for an agent.
     */
    public static function averageDurationByAgent($agentId)
    {
        return self::byAgent($agentId)
            ->whereNotNull('duration_ms')
            ->avg('duration_ms');
    }

    /**
     * Get success rate for an agent.
     */
    public static function successRateByAgent($agentId)
    {
        $total = self::byAgent($agentId)->count();
        if ($total === 0) return 0;
        
        $successful = self::byAgent($agentId)->successful()->count();
        return ($successful / $total) * 100;
    }

    /**
     * Mark execution as completed.
     */
    public function markCompleted($outputContext = [], $tokensUsed = 0)
    {
        $this->update([
            'status' => 'completed',
            'output_context' => $outputContext,
            'tokens_used' => $tokensUsed,
            'completed_at' => now(),
            'duration_ms' => $this->started_at ? 
                now()->diffInMilliseconds($this->started_at) : null
        ]);
    }

    /**
     * Mark execution as failed.
     */
    public function markFailed($error, $retryCount = 0)
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $error,
            'retry_count' => $retryCount,
            'completed_at' => now(),
            'duration_ms' => $this->started_at ? 
                now()->diffInMilliseconds($this->started_at) : null
        ]);
    }
}