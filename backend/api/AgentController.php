<?php

namespace App\Http\Controllers;

use App\Models\AgentExecution;
use App\Services\OrchestrationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Exception;

class AgentController extends Controller
{
    protected $orchestrationService;

    public function __construct(OrchestrationService $orchestrationService)
    {
        $this->orchestrationService = $orchestrationService;
    }

    /**
     * Start agent execution.
     */
    public function execute(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'run_id' => 'required|string',
            'agent_id' => 'required|string|max:100',
            'capability' => 'required|string|max:255',
            'sequence_number' => 'required|integer|min:1',
            'input_context' => 'nullable|array',
            'metadata' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $execution = $this->orchestrationService->recordAgentExecution(
                $request->run_id,
                $request->agent_id,
                $request->capability,
                $request->sequence_number,
                $request->get('input_context', [])
            );

            return response()->json([
                'success' => true,
                'execution_id' => $execution->id,
                'status' => 'started',
                'timestamp' => $execution->started_at
            ], 201);

        } catch (Exception $e) {
            Log::error('Failed to start agent execution', [
                'error' => $e->getMessage(),
                'agent_id' => $request->agent_id
            ]);

            return response()->json([
                'error' => 'Failed to start agent execution',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Complete agent execution.
     */
    public function complete(Request $request, int $executionId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'output_context' => 'nullable|array',
            'tokens_used' => 'nullable|integer|min:0',
            'error_message' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $execution = $this->orchestrationService->completeAgentExecution(
                $executionId,
                $request->get('output_context', []),
                $request->get('tokens_used', 0)
            );

            return response()->json([
                'success' => true,
                'execution_id' => $execution->id,
                'status' => $execution->status,
                'duration_ms' => $execution->duration_ms,
                'tokens_used' => $execution->tokens_used
            ]);

        } catch (Exception $e) {
            Log::error('Failed to complete agent execution', [
                'error' => $e->getMessage(),
                'execution_id' => $executionId
            ]);

            return response()->json([
                'error' => 'Failed to complete agent execution',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get agent performance metrics.
     */
    public function performance(Request $request): JsonResponse
    {
        try {
            $performance = $this->orchestrationService->getAgentPerformance();

            // Filter by agent_id if provided
            if ($request->has('agent_id')) {
                $performance = $performance->where('agent_id', $request->agent_id);
            }

            return response()->json([
                'success' => true,
                'agents' => $performance,
                'timestamp' => now()->toIso8601String()
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get agent performance', ['error' => $e->getMessage()]);
            
            return response()->json([
                'error' => 'Failed to get agent performance',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get agent capabilities (from configuration).
     */
    public function capabilities(): JsonResponse
    {
        try {
            // Load agent capabilities from router config
            $configPath = base_path('config/router_config.json');
            
            if (!file_exists($configPath)) {
                return response()->json([
                    'error' => 'Configuration not found'
                ], 404);
            }

            $config = json_decode(file_get_contents($configPath), true);
            
            if (!isset($config['agents'])) {
                return response()->json([
                    'error' => 'No agents configured'
                ], 404);
            }

            $capabilities = [];
            foreach ($config['agents'] as $agentId => $agentConfig) {
                $capabilities[$agentId] = [
                    'id' => $agentId,
                    'name' => $agentConfig['name'] ?? $agentId,
                    'capabilities' => $agentConfig['capabilities'] ?? [],
                    'token_budget' => $agentConfig['token_budget'] ?? 0,
                    'priority' => $agentConfig['priority'] ?? 0,
                    'active' => $agentConfig['active'] ?? true
                ];
            }

            return response()->json([
                'success' => true,
                'agents' => $capabilities,
                'total_count' => count($capabilities),
                'router_version' => $config['router_version'] ?? 'unknown'
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get agent capabilities', ['error' => $e->getMessage()]);
            
            return response()->json([
                'error' => 'Failed to get agent capabilities',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}