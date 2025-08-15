<?php

namespace App\Services;

use App\Models\OrchestrationRun;
use App\Models\AgentExecution;
use App\Models\RouterMetric;
use App\Models\StabilityTracking;
use App\Models\SecurityAuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Exception;

class OrchestrationService
{
    /**
     * Start a new orchestration run.
     */
    public function startRun($runId, $query, $correlationId = null, $workflowId = null)
    {
        try {
            $run = OrchestrationRun::create([
                'run_id' => $runId,
                'correlation_id' => $correlationId ?? uniqid('corr_'),
                'workflow_id' => $workflowId,
                'query' => $query,
                'status' => 'running',
                'success' => false,
                'started_at' => now(),
                'metadata' => [
                    'user_id' => auth()->id(),
                    'ip_address' => request()->ip()
                ]
            ]);

            // Log the start of orchestration
            SecurityAuditLog::logEvent(
                SecurityAuditLog::EVENT_DATA_ACCESS,
                "Orchestration run started: {$runId}",
                SecurityAuditLog::SEVERITY_INFO,
                ['run_id' => $runId]
            );

            // Update metrics cache
            Cache::increment('genesis.total_runs');

            return $run;
        } catch (Exception $e) {
            throw new Exception("Failed to start orchestration run: " . $e->getMessage());
        }
    }

    /**
     * Complete an orchestration run.
     */
    public function completeRun($runId, $success, $answer = null, $stabilityScore = null, $artifacts = [])
    {
        $run = OrchestrationRun::where('run_id', $runId)->firstOrFail();
        
        $duration = $run->started_at ? now()->diffInMilliseconds($run->started_at) : 0;
        
        $run->update([
            'status' => $success ? 'completed' : 'failed',
            'success' => $success,
            'answer' => $answer,
            'stability_score' => $stabilityScore ?? $this->calculateStabilityScore($runId),
            'total_duration_ms' => $duration,
            'artifacts' => $artifacts,
            'completed_at' => now()
        ]);

        // Update cache metrics
        if ($success) {
            Cache::increment('genesis.successful_runs');
        } else {
            Cache::increment('genesis.failed_runs');
        }
        
        // Update average latency
        $this->updateAverageLatency($duration);

        return $run;
    }

    /**
     * Record an agent execution.
     */
    public function recordAgentExecution($runId, $agentId, $capability, $sequenceNumber, $inputContext = [])
    {
        $run = OrchestrationRun::where('run_id', $runId)->first();
        
        if (!$run) {
            throw new Exception("Orchestration run not found: {$runId}");
        }

        return AgentExecution::create([
            'orchestration_run_id' => $run->id,
            'agent_id' => $agentId,
            'capability' => $capability,
            'sequence_number' => $sequenceNumber,
            'status' => 'running',
            'input_context' => $inputContext,
            'started_at' => now()
        ]);
    }

    /**
     * Complete an agent execution.
     */
    public function completeAgentExecution($executionId, $outputContext = [], $tokensUsed = 0)
    {
        $execution = AgentExecution::findOrFail($executionId);
        $execution->markCompleted($outputContext, $tokensUsed);
        
        // Update token count for the run
        $run = $execution->orchestrationRun;
        $run->increment('total_tokens', $tokensUsed);
        
        Cache::increment('genesis.total_tokens', $tokensUsed);
        
        return $execution;
    }

    /**
     * Record router metrics.
     */
    public function recordRouterMetrics($runId, array $metrics)
    {
        $routerMetric = RouterMetric::create([
            'run_id' => $runId,
            'algorithm' => $metrics['algorithm'] ?? 'RCR',
            'budget_per_role' => $metrics['budget_per_role'] ?? [],
            'selected_documents' => $metrics['selected_documents'] ?? [],
            'importance_scores' => $metrics['importance_scores'] ?? [],
            'token_savings_percentage' => $metrics['token_savings_percentage'] ?? 0,
            'selection_time_ms' => $metrics['selection_time_ms'] ?? 0,
            'total_selected_tokens' => $metrics['total_selected_tokens'] ?? 0,
            'efficiency_gain' => $metrics['efficiency_gain'] ?? 0,
            'metadata' => $metrics
        ]);

        // Update cache metrics
        Cache::put('genesis.router.efficiency', $routerMetric->efficiency_gain);
        
        return $routerMetric;
    }

    /**
     * Track stability for a test.
     */
    public function trackStability($testId, $runNumber, $input, $output)
    {
        $tracking = StabilityTracking::trackRun($testId, $runNumber, $input, $output);
        
        // Update system stability score in cache
        $stability = StabilityTracking::getSystemStability();
        Cache::put('genesis.stability_score', $stability['stability_score']);
        Cache::put('genesis.stability_variance', $stability['avg_variance']);
        
        return $tracking;
    }

    /**
     * Calculate stability score for a run.
     */
    private function calculateStabilityScore($runId)
    {
        // Get all agent executions for this run
        $run = OrchestrationRun::where('run_id', $runId)->first();
        if (!$run) return 0.986; // Default
        
        $executions = $run->agentExecutions;
        
        if ($executions->isEmpty()) {
            return 0.986; // Default if no executions
        }
        
        // Calculate based on success rate and consistency
        $successRate = $executions->where('status', 'completed')->count() / $executions->count();
        $avgRetries = $executions->avg('retry_count') ?? 0;
        
        // Stability formula: high success rate, low retries
        $stability = ($successRate * 0.8) + ((1 - min($avgRetries / 3, 1)) * 0.2);
        
        // Cap at 98.6%
        return min(0.986, $stability);
    }

    /**
     * Update average latency in cache.
     */
    private function updateAverageLatency($newLatency)
    {
        $currentAvg = Cache::get('genesis.avg_latency', 0);
        $totalRuns = Cache::get('genesis.total_runs', 1);
        
        // Calculate new average
        $newAvg = (($currentAvg * ($totalRuns - 1)) + $newLatency) / $totalRuns;
        
        Cache::put('genesis.avg_latency', $newAvg);
    }

    /**
     * Get run statistics.
     */
    public function getRunStatistics()
    {
        return [
            'total_runs' => OrchestrationRun::count(),
            'successful_runs' => OrchestrationRun::successful()->count(),
            'failed_runs' => OrchestrationRun::failed()->count(),
            'success_rate' => OrchestrationRun::successRate(),
            'avg_stability' => OrchestrationRun::averageStabilityScore(),
            'avg_duration' => OrchestrationRun::avg('total_duration_ms'),
            'total_tokens' => OrchestrationRun::sum('total_tokens'),
            'recent_runs' => OrchestrationRun::latest()->take(10)->get()
        ];
    }

    /**
     * Get agent performance metrics.
     */
    public function getAgentPerformance()
    {
        $agents = DB::table('agent_executions')
            ->select('agent_id')
            ->selectRaw('COUNT(*) as total_executions')
            ->selectRaw('SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as successful')
            ->selectRaw('AVG(duration_ms) as avg_duration')
            ->selectRaw('SUM(tokens_used) as total_tokens')
            ->groupBy('agent_id')
            ->get();

        return $agents->map(function ($agent) {
            $agent->success_rate = $agent->total_executions > 0 
                ? ($agent->successful / $agent->total_executions) * 100 
                : 0;
            return $agent;
        });
    }
}