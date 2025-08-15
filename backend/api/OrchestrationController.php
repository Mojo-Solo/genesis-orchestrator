<?php

namespace App\Http\Controllers;

use App\Models\OrchestrationRun;
use App\Models\RouterMetric;
use App\Models\StabilityTracking;
use App\Models\MemoryItem;
use App\Services\OrchestrationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Exception;

class OrchestrationController extends Controller
{
    protected $orchestrationService;

    public function __construct(OrchestrationService $orchestrationService)
    {
        $this->orchestrationService = $orchestrationService;
    }

    /**
     * Start a new orchestration run.
     */
    public function start(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|max:5000',
            'correlation_id' => 'nullable|string|max:100',
            'workflow_id' => 'nullable|string|max:100',
            'metadata' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $runId = uniqid('run_');
            
            $run = $this->orchestrationService->startRun(
                $runId,
                $request->query,
                $request->correlation_id,
                $request->workflow_id
            );

            return response()->json([
                'success' => true,
                'run_id' => $runId,
                'correlation_id' => $run->correlation_id,
                'status' => 'started',
                'timestamp' => $run->started_at
            ], 201);

        } catch (Exception $e) {
            Log::error('Failed to start orchestration run', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to start orchestration',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Complete an orchestration run.
     */
    public function complete(Request $request, string $runId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'success' => 'required|boolean',
            'answer' => 'nullable|string',
            'stability_score' => 'nullable|numeric|min:0|max:1',
            'artifacts' => 'nullable|array',
            'error_message' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $run = $this->orchestrationService->completeRun(
                $runId,
                $request->success,
                $request->answer,
                $request->stability_score,
                $request->artifacts ?? []
            );

            return response()->json([
                'success' => true,
                'run_id' => $runId,
                'status' => $run->status,
                'duration_ms' => $run->total_duration_ms,
                'stability_score' => $run->stability_score,
                'timestamp' => $run->completed_at
            ]);

        } catch (Exception $e) {
            Log::error('Failed to complete orchestration run', [
                'run_id' => $runId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to complete orchestration',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get run status.
     */
    public function status(string $runId): JsonResponse
    {
        try {
            $run = OrchestrationRun::where('run_id', $runId)->firstOrFail();

            return response()->json([
                'run_id' => $run->run_id,
                'status' => $run->status,
                'success' => $run->success,
                'query' => $run->query,
                'answer' => $run->answer,
                'stability_score' => $run->stability_score,
                'total_tokens' => $run->total_tokens,
                'duration_ms' => $run->total_duration_ms,
                'started_at' => $run->started_at,
                'completed_at' => $run->completed_at,
                'agent_executions' => $run->agentExecutions->count()
            ]);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'Run not found',
                'run_id' => $runId
            ], 404);
        }
    }

    /**
     * Get run statistics.
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = $this->orchestrationService->getRunStatistics();
            
            return response()->json([
                'success' => true,
                'statistics' => $stats
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get statistics', ['error' => $e->getMessage()]);
            
            return response()->json([
                'error' => 'Failed to get statistics',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get run history.
     */
    public function history(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'nullable|integer|min:1|max:100',
            'offset' => 'nullable|integer|min:0',
            'status' => 'nullable|string|in:running,completed,failed'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $query = OrchestrationRun::query();

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $limit = $request->get('limit', 20);
            $offset = $request->get('offset', 0);

            $runs = $query->orderBy('created_at', 'desc')
                          ->skip($offset)
                          ->take($limit)
                          ->get();

            return response()->json([
                'success' => true,
                'runs' => $runs,
                'total' => OrchestrationRun::count(),
                'limit' => $limit,
                'offset' => $offset
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get history', ['error' => $e->getMessage()]);
            
            return response()->json([
                'error' => 'Failed to get history',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Record router metrics.
     */
    public function recordRouterMetrics(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'run_id' => 'required|string',
            'algorithm' => 'nullable|string',
            'budget_per_role' => 'nullable|array',
            'selected_documents' => 'nullable|array',
            'importance_scores' => 'nullable|array',
            'token_savings_percentage' => 'nullable|numeric',
            'selection_time_ms' => 'nullable|integer',
            'total_selected_tokens' => 'nullable|integer',
            'efficiency_gain' => 'nullable|numeric'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $metrics = $this->orchestrationService->recordRouterMetrics(
                $request->run_id,
                $request->all()
            );

            return response()->json([
                'success' => true,
                'metrics_id' => $metrics->id
            ], 201);

        } catch (Exception $e) {
            Log::error('Failed to record router metrics', ['error' => $e->getMessage()]);
            
            return response()->json([
                'error' => 'Failed to record metrics',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get router efficiency stats.
     */
    public function routerEfficiency(): JsonResponse
    {
        try {
            $rcr = RouterMetric::getMetricsByAlgorithm('RCR');
            $lag = RouterMetric::getMetricsByAlgorithm('LAG');
            $bmad = RouterMetric::getMetricsByAlgorithm('BMAD');

            return response()->json([
                'success' => true,
                'algorithms' => [
                    'RCR' => $rcr,
                    'LAG' => $lag,
                    'BMAD' => $bmad
                ],
                'overall' => [
                    'avg_token_savings' => RouterMetric::averageTokenSavings(),
                    'avg_selection_time' => RouterMetric::averageSelectionTime(),
                    'total_runs' => RouterMetric::count()
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get router efficiency', ['error' => $e->getMessage()]);
            
            return response()->json([
                'error' => 'Failed to get efficiency stats',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Track stability test.
     */
    public function trackStability(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'test_id' => 'required|string',
            'run_number' => 'required|integer',
            'input' => 'required',
            'output' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $tracking = $this->orchestrationService->trackStability(
                $request->test_id,
                $request->run_number,
                $request->input,
                $request->output
            );

            return response()->json([
                'success' => true,
                'tracking_id' => $tracking->id,
                'exact_match' => $tracking->exact_match,
                'semantic_similarity' => $tracking->semantic_similarity,
                'variance_score' => $tracking->variance_score
            ], 201);

        } catch (Exception $e) {
            Log::error('Failed to track stability', ['error' => $e->getMessage()]);
            
            return response()->json([
                'error' => 'Failed to track stability',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get stability metrics.
     */
    public function stabilityMetrics(Request $request): JsonResponse
    {
        try {
            $systemStability = StabilityTracking::getSystemStability();
            
            $response = [
                'success' => true,
                'system' => $systemStability
            ];

            if ($request->has('test_id')) {
                $testStability = StabilityTracking::getTestStability($request->test_id);
                $response['test'] = [
                    'test_id' => $request->test_id,
                    'stability_score' => $testStability
                ];
            }

            return response()->json($response);

        } catch (Exception $e) {
            Log::error('Failed to get stability metrics', ['error' => $e->getMessage()]);
            
            return response()->json([
                'error' => 'Failed to get stability metrics',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store memory item.
     */
    public function storeMemory(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'key' => 'required|string|max:255',
            'value' => 'required',
            'namespace' => 'nullable|string|max:100',
            'ttl' => 'nullable|integer|min:1',
            'metadata' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $item = MemoryItem::store(
                $request->key,
                $request->value,
                $request->get('namespace', 'default'),
                $request->ttl,
                $request->get('metadata', [])
            );

            return response()->json([
                'success' => true,
                'memory_id' => $item->id,
                'expires_at' => $item->expires_at
            ], 201);

        } catch (Exception $e) {
            Log::error('Failed to store memory', ['error' => $e->getMessage()]);
            
            return response()->json([
                'error' => 'Failed to store memory',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Retrieve memory item.
     */
    public function retrieveMemory(string $key, Request $request): JsonResponse
    {
        try {
            $namespace = $request->get('namespace', 'default');
            $item = MemoryItem::retrieve($key, $namespace);

            if (!$item) {
                return response()->json([
                    'error' => 'Memory item not found',
                    'key' => $key,
                    'namespace' => $namespace
                ], 404);
            }

            return response()->json([
                'success' => true,
                'key' => $item->key,
                'value' => $item->value,
                'metadata' => $item->metadata,
                'access_count' => $item->access_count,
                'last_accessed' => $item->last_accessed_at,
                'expires_at' => $item->expires_at
            ]);

        } catch (Exception $e) {
            Log::error('Failed to retrieve memory', ['error' => $e->getMessage()]);
            
            return response()->json([
                'error' => 'Failed to retrieve memory',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clean up expired memory items.
     */
    public function cleanupMemory(): JsonResponse
    {
        try {
            $deleted = MemoryItem::cleanupExpired();

            return response()->json([
                'success' => true,
                'deleted_count' => $deleted
            ]);

        } catch (Exception $e) {
            Log::error('Failed to cleanup memory', ['error' => $e->getMessage()]);
            
            return response()->json([
                'error' => 'Failed to cleanup memory',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle Temporal workflow completion webhook.
     */
    public function temporalWebhook(Request $request): JsonResponse
    {
        // This would be implemented when Temporal integration is complete
        Log::info('Temporal webhook received', $request->all());

        return response()->json(['success' => true]);
    }

    /**
     * Handle OpenTelemetry metrics push.
     */
    public function otelMetrics(Request $request): JsonResponse
    {
        // This would be implemented when OTel integration is complete
        Log::info('OTel metrics received', $request->all());

        return response()->json(['success' => true]);
    }
}