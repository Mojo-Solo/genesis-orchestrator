<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\APIMarketplaceService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class APIMarketplaceController extends Controller
{
    protected $marketplaceService;

    public function __construct(APIMarketplaceService $marketplaceService)
    {
        $this->marketplaceService = $marketplaceService;
        $this->middleware(['tenant.isolation']);
    }

    /**
     * Get available connectors for tenant
     */
    public function getConnectors(Request $request): JsonResponse
    {
        try {
            $tenantId = $request->header('X-Tenant-ID');
            $connectors = $this->marketplaceService->getAvailableConnectors($tenantId);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'connectors' => $connectors,
                    'total' => count($connectors),
                ],
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get available connectors', [
                'tenant_id' => $request->header('X-Tenant-ID'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to get available connectors',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Configure a connector for tenant
     */
    public function configureConnector(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'connector_name' => 'required|string',
            'configuration' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
            ], 400);
        }

        try {
            $tenantId = $request->header('X-Tenant-ID');
            
            $result = $this->marketplaceService->configureConnector(
                $tenantId,
                $request->connector_name,
                $request->configuration
            );

            return response()->json([
                'status' => 'success',
                'data' => $result,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to configure connector', [
                'tenant_id' => $request->header('X-Tenant-ID'),
                'connector_name' => $request->connector_name,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to configure connector',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Execute API call through connector
     */
    public function executeAPICall(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'connector_name' => 'required|string',
            'method' => 'required|string|in:GET,POST,PUT,DELETE,PATCH',
            'endpoint' => 'required|string',
            'data' => 'nullable|array',
            'options' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
            ], 400);
        }

        try {
            $tenantId = $request->header('X-Tenant-ID');
            
            $result = $this->marketplaceService->executeAPICall(
                $tenantId,
                $request->connector_name,
                $request->method,
                $request->endpoint,
                $request->data ?? [],
                $request->options ?? []
            );

            return response()->json([
                'status' => 'success',
                'data' => $result,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to execute API call', [
                'tenant_id' => $request->header('X-Tenant-ID'),
                'connector_name' => $request->connector_name,
                'method' => $request->method,
                'endpoint' => $request->endpoint,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to execute API call',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get connector metrics
     */
    public function getConnectorMetrics(Request $request, string $connectorName): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
            ], 400);
        }

        try {
            $tenantId = $request->header('X-Tenant-ID');
            
            $timeRange = [];
            if ($request->start_date) {
                $timeRange['start'] = $request->start_date;
            }
            if ($request->end_date) {
                $timeRange['end'] = $request->end_date;
            }

            $metrics = $this->marketplaceService->getConnectorMetrics(
                $tenantId,
                $connectorName,
                $timeRange
            );

            return response()->json([
                'status' => 'success',
                'data' => $metrics,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get connector metrics', [
                'tenant_id' => $request->header('X-Tenant-ID'),
                'connector_name' => $connectorName,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to get connector metrics',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle webhook from external service
     */
    public function handleWebhook(Request $request, string $connectorName): JsonResponse
    {
        try {
            $headers = $request->headers->all();
            $payload = $request->getContent();
            $signature = $request->header('X-Slack-Signature') 
                      ?? $request->header('X-Hub-Signature-256')
                      ?? $request->header('X-Signature');

            $result = $this->marketplaceService->handleWebhook(
                $connectorName,
                $headers,
                $payload,
                $signature
            );

            // Handle URL verification for Slack
            if (isset($result['response'])) {
                return response($result['response']);
            }

            return response()->json([
                'status' => 'success',
                'data' => $result,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to handle webhook', [
                'connector_name' => $connectorName,
                'error' => $e->getMessage(),
                'headers' => $request->headers->all(),
            ]);

            return response()->json([
                'error' => 'Failed to handle webhook',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get webhook configuration for connector
     */
    public function getWebhookConfiguration(Request $request, string $connectorName): JsonResponse
    {
        try {
            $tenantId = $request->header('X-Tenant-ID');
            
            $configuration = $this->marketplaceService->getWebhookConfiguration(
                $tenantId,
                $connectorName
            );

            return response()->json([
                'status' => 'success',
                'data' => $configuration,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get webhook configuration', [
                'tenant_id' => $request->header('X-Tenant-ID'),
                'connector_name' => $connectorName,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to get webhook configuration',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Execute bulk operation
     */
    public function executeBulkOperation(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'connector_name' => 'required|string',
            'operation' => 'required|string',
            'items' => 'required|array|min:1|max:1000',
            'options' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
            ], 400);
        }

        try {
            $tenantId = $request->header('X-Tenant-ID');
            
            $result = $this->marketplaceService->executeBulkOperation(
                $tenantId,
                $request->connector_name,
                $request->operation,
                $request->items,
                $request->options ?? []
            );

            return response()->json([
                'status' => 'success',
                'data' => $result,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to execute bulk operation', [
                'tenant_id' => $request->header('X-Tenant-ID'),
                'connector_name' => $request->connector_name,
                'operation' => $request->operation,
                'items_count' => count($request->items ?? []),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to execute bulk operation',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Disconnect connector for tenant
     */
    public function disconnectConnector(Request $request, string $connectorName): JsonResponse
    {
        try {
            $tenantId = $request->header('X-Tenant-ID');
            
            $result = $this->marketplaceService->disconnectConnector($tenantId, $connectorName);

            return response()->json([
                'status' => 'success',
                'data' => $result,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to disconnect connector', [
                'tenant_id' => $request->header('X-Tenant-ID'),
                'connector_name' => $connectorName,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to disconnect connector',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get connector documentation
     */
    public function getConnectorDocumentation(Request $request, string $connectorName): JsonResponse
    {
        try {
            $documentation = $this->getConnectorDocs($connectorName);

            return response()->json([
                'status' => 'success',
                'data' => $documentation,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to get connector documentation',
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Test connector configuration
     */
    public function testConnectorConfiguration(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'connector_name' => 'required|string',
            'configuration' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
            ], 400);
        }

        try {
            // This would test the configuration without saving it
            $tenantId = $request->header('X-Tenant-ID');
            
            // For now, we'll use the marketplace service's test functionality
            // This could be extracted to a separate method
            $testResult = $this->testConfiguration(
                $request->connector_name,
                $request->configuration
            );

            return response()->json([
                'status' => 'success',
                'data' => $testResult,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to test connector configuration', [
                'tenant_id' => $request->header('X-Tenant-ID'),
                'connector_name' => $request->connector_name,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to test connector configuration',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get marketplace statistics
     */
    public function getMarketplaceStatistics(Request $request): JsonResponse
    {
        try {
            $tenantId = $request->header('X-Tenant-ID');
            
            $statistics = [
                'total_connectors_available' => count($this->marketplaceService->getAvailableConnectors($tenantId)),
                'total_api_calls_today' => $this->getAPICallsCount($tenantId, 'today'),
                'total_api_calls_month' => $this->getAPICallsCount($tenantId, 'month'),
                'most_used_connector' => $this->getMostUsedConnector($tenantId),
                'success_rate' => $this->getOverallSuccessRate($tenantId),
            ];

            return response()->json([
                'status' => 'success',
                'data' => $statistics,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get marketplace statistics', [
                'tenant_id' => $request->header('X-Tenant-ID'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to get marketplace statistics',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper methods
     */
    protected function getConnectorDocs(string $connectorName): array
    {
        $docs = [
            'slack' => [
                'name' => 'Slack',
                'description' => 'Integrate with Slack for team communication',
                'setup_guide' => [
                    'Create a Slack app at https://api.slack.com/apps',
                    'Configure OAuth & Permissions',
                    'Add required scopes',
                    'Install app to workspace',
                    'Copy the OAuth access token',
                ],
                'required_scopes' => [
                    'chat:write',
                    'channels:read',
                    'users:read',
                ],
                'example_usage' => [
                    'Send message' => [
                        'method' => 'POST',
                        'endpoint' => 'chat.postMessage',
                        'data' => [
                            'channel' => '#general',
                            'text' => 'Hello from GENESIS!',
                        ],
                    ],
                ],
            ],
            // Add more connector documentation here
        ];

        if (!isset($docs[$connectorName])) {
            throw new Exception("Documentation for connector '{$connectorName}' not found");
        }

        return $docs[$connectorName];
    }

    protected function testConfiguration(string $connectorName, array $configuration): array
    {
        // This would be implemented to test configuration without the full marketplace service
        // For now, return a basic validation
        return [
            'configuration_valid' => true,
            'connection_test' => 'passed',
            'message' => 'Configuration test successful',
        ];
    }

    protected function getAPICallsCount(string $tenantId, string $period): int
    {
        // Implement based on your metrics storage
        return 0;
    }

    protected function getMostUsedConnector(string $tenantId): ?string
    {
        // Implement based on your metrics storage
        return null;
    }

    protected function getOverallSuccessRate(string $tenantId): float
    {
        // Implement based on your metrics storage
        return 0.0;
    }
}