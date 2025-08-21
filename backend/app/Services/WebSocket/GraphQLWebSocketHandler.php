<?php

namespace App\Services\WebSocket;

use App\Services\AdvancedSecurityService;
use App\Services\AdvancedMonitoringService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use Ratchet\WebSocket\WsServerInterface;

/**
 * GraphQL WebSocket Handler
 * 
 * Handles real-time GraphQL subscriptions over WebSocket connections
 * with comprehensive security, monitoring, and performance optimization
 */
class GraphQLWebSocketHandler implements MessageComponentInterface
{
    protected AdvancedSecurityService $securityService;
    protected AdvancedMonitoringService $monitoringService;
    protected \SplObjectStorage $connections;
    protected array $subscriptions = [];
    protected array $connectionMetadata = [];

    public function __construct(
        AdvancedSecurityService $securityService,
        AdvancedMonitoringService $monitoringService
    ) {
        $this->securityService = $securityService;
        $this->monitoringService = $monitoringService;
        $this->connections = new \SplObjectStorage();
    }

    /**
     * Handle new WebSocket connection
     */
    public function onOpen(ConnectionInterface $conn): void
    {
        $startTime = microtime(true);
        
        try {
            // Store connection
            $this->connections->attach($conn);
            
            // Initialize connection metadata
            $this->connectionMetadata[$conn->resourceId] = [
                'id' => $conn->resourceId,
                'connected_at' => now()->toISOString(),
                'last_activity' => now()->toISOString(),
                'user_id' => null,
                'tenant_id' => null,
                'subscriptions' => [],
                'ip_address' => $this->getConnectionIP($conn),
                'user_agent' => $this->getConnectionUserAgent($conn),
                'protocol' => 'graphql-ws',
                'authentication_status' => 'pending',
            ];

            // Record connection
            $this->monitoringService->recordMetric('websocket.connection.opened', 1, [
                'connection_id' => $conn->resourceId,
                'ip_address' => $this->connectionMetadata[$conn->resourceId]['ip_address'],
                'total_connections' => $this->connections->count(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);

            // Send connection acknowledgment
            $this->sendMessage($conn, [
                'type' => 'connection_ack',
                'payload' => [
                    'connection_id' => $conn->resourceId,
                    'server_time' => now()->toISOString(),
                    'protocol_version' => '1.0',
                ],
            ]);

            Log::info("WebSocket connection opened", [
                'connection_id' => $conn->resourceId,
                'total_connections' => $this->connections->count(),
            ]);

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('websocket.connection.open_error', 1, [
                'error' => $e->getMessage(),
                'connection_id' => $conn->resourceId ?? 'unknown',
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            
            $conn->close(1011, 'Server error during connection setup');
        }
    }

    /**
     * Handle incoming WebSocket messages
     */
    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $startTime = microtime(true);
        
        try {
            // Update connection activity
            $this->updateConnectionActivity($from);

            // Parse message
            $message = json_decode($msg, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON message');
            }

            // Validate message structure
            if (!isset($message['type'])) {
                throw new \Exception('Message type is required');
            }

            // Handle message based on type
            switch ($message['type']) {
                case 'connection_init':
                    $this->handleConnectionInit($from, $message);
                    break;
                    
                case 'start':
                    $this->handleSubscriptionStart($from, $message);
                    break;
                    
                case 'stop':
                    $this->handleSubscriptionStop($from, $message);
                    break;
                    
                case 'connection_terminate':
                    $this->handleConnectionTerminate($from);
                    break;
                    
                case 'ping':
                    $this->handlePing($from, $message);
                    break;
                    
                case 'pong':
                    $this->handlePong($from, $message);
                    break;
                    
                default:
                    throw new \Exception("Unknown message type: {$message['type']}");
            }

            // Record successful message handling
            $this->monitoringService->recordMetric('websocket.message.handled', 1, [
                'connection_id' => $from->resourceId,
                'message_type' => $message['type'],
                'user_id' => $this->connectionMetadata[$from->resourceId]['user_id'] ?? null,
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);

        } catch (\Exception $e) {
            // Record message handling error
            $this->monitoringService->recordMetric('websocket.message.error', 1, [
                'connection_id' => $from->resourceId,
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);

            // Send error response
            $this->sendError($from, $e->getMessage(), $message['id'] ?? null);
            
            Log::warning("WebSocket message error", [
                'connection_id' => $from->resourceId,
                'error' => $e->getMessage(),
                'message' => $msg,
            ]);
        }
    }

    /**
     * Handle connection close
     */
    public function onClose(ConnectionInterface $conn): void
    {
        $startTime = microtime(true);
        
        try {
            $connectionId = $conn->resourceId;
            $metadata = $this->connectionMetadata[$connectionId] ?? [];

            // Clean up subscriptions
            $this->cleanupConnectionSubscriptions($conn);

            // Remove connection
            $this->connections->detach($conn);
            unset($this->connectionMetadata[$connectionId]);

            // Record disconnection
            $this->monitoringService->recordMetric('websocket.connection.closed', 1, [
                'connection_id' => $connectionId,
                'user_id' => $metadata['user_id'] ?? null,
                'tenant_id' => $metadata['tenant_id'] ?? null,
                'duration_seconds' => isset($metadata['connected_at']) 
                    ? now()->diffInSeconds($metadata['connected_at']) 
                    : 0,
                'subscriptions_count' => count($metadata['subscriptions'] ?? []),
                'total_connections' => $this->connections->count(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);

            Log::info("WebSocket connection closed", [
                'connection_id' => $connectionId,
                'total_connections' => $this->connections->count(),
            ]);

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('websocket.connection.close_error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
        }
    }

    /**
     * Handle connection errors
     */
    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $connectionId = $conn->resourceId;
        $metadata = $this->connectionMetadata[$connectionId] ?? [];

        // Record error
        $this->monitoringService->recordMetric('websocket.connection.error', 1, [
            'connection_id' => $connectionId,
            'user_id' => $metadata['user_id'] ?? null,
            'error' => $e->getMessage(),
        ]);

        Log::error("WebSocket connection error", [
            'connection_id' => $connectionId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        // Close connection
        $conn->close(1011, 'Internal server error');
    }

    /**
     * Handle connection initialization
     */
    protected function handleConnectionInit(ConnectionInterface $conn, array $message): void
    {
        $payload = $message['payload'] ?? [];
        
        // Extract authentication token
        $authToken = $payload['Authorization'] ?? $payload['authorization'] ?? null;
        
        if ($authToken) {
            try {
                // Authenticate user
                $user = $this->authenticateConnection($authToken);
                
                if ($user) {
                    // Update connection metadata
                    $this->connectionMetadata[$conn->resourceId]['user_id'] = $user->id;
                    $this->connectionMetadata[$conn->resourceId]['tenant_id'] = $user->tenant_id;
                    $this->connectionMetadata[$conn->resourceId]['authentication_status'] = 'authenticated';
                    
                    // Apply security validation
                    $this->securityService->validateWebSocketConnection($user, $conn);
                    
                    // Send success response
                    $this->sendMessage($conn, [
                        'type' => 'connection_ack',
                        'payload' => [
                            'user_id' => $user->id,
                            'tenant_id' => $user->tenant_id,
                        ],
                    ]);
                    
                    $this->monitoringService->recordMetric('websocket.authentication.success', 1, [
                        'connection_id' => $conn->resourceId,
                        'user_id' => $user->id,
                        'tenant_id' => $user->tenant_id,
                    ]);
                    
                } else {
                    throw new \Exception('Authentication failed');
                }
                
            } catch (\Exception $e) {
                $this->connectionMetadata[$conn->resourceId]['authentication_status'] = 'failed';
                
                $this->monitoringService->recordSecurityEvent('websocket_auth_failed', [
                    'connection_id' => $conn->resourceId,
                    'ip_address' => $this->connectionMetadata[$conn->resourceId]['ip_address'],
                    'error' => $e->getMessage(),
                ]);
                
                $this->sendError($conn, 'Authentication failed');
                $conn->close(1008, 'Authentication failed');
                return;
            }
        } else {
            // Anonymous connection
            $this->connectionMetadata[$conn->resourceId]['authentication_status'] = 'anonymous';
            
            $this->sendMessage($conn, [
                'type' => 'connection_ack',
                'payload' => ['anonymous' => true],
            ]);
        }
    }

    /**
     * Handle subscription start
     */
    protected function handleSubscriptionStart(ConnectionInterface $conn, array $message): void
    {
        $subscriptionId = $message['id'] ?? null;
        $payload = $message['payload'] ?? [];
        
        if (!$subscriptionId) {
            throw new \Exception('Subscription ID is required');
        }

        // Validate subscription payload
        if (!isset($payload['query'])) {
            throw new \Exception('GraphQL query is required');
        }

        $metadata = $this->connectionMetadata[$conn->resourceId];
        $user = $metadata['user_id'] ? \App\Models\User::find($metadata['user_id']) : null;

        // Authorization check
        if (!$this->authorizeSubscription($user, $payload)) {
            $this->sendError($conn, 'Unauthorized subscription', $subscriptionId);
            return;
        }

        // Parse GraphQL subscription
        $subscription = $this->parseGraphQLSubscription($payload);
        
        // Apply rate limiting
        if (!$this->checkSubscriptionRateLimit($user, $subscription)) {
            $this->sendError($conn, 'Subscription rate limit exceeded', $subscriptionId);
            return;
        }

        // Store subscription
        $this->subscriptions[$subscriptionId] = [
            'id' => $subscriptionId,
            'connection' => $conn,
            'connection_id' => $conn->resourceId,
            'user_id' => $metadata['user_id'],
            'tenant_id' => $metadata['tenant_id'],
            'query' => $payload['query'],
            'variables' => $payload['variables'] ?? [],
            'operation_name' => $payload['operationName'] ?? null,
            'subscription_type' => $subscription['type'],
            'subscription_field' => $subscription['field'],
            'arguments' => $subscription['arguments'],
            'created_at' => now()->toISOString(),
            'last_activity' => now()->toISOString(),
        ];

        // Add to connection metadata
        $this->connectionMetadata[$conn->resourceId]['subscriptions'][] = $subscriptionId;

        // Send confirmation
        $this->sendMessage($conn, [
            'type' => 'data',
            'id' => $subscriptionId,
            'payload' => [
                'data' => [
                    'subscription_started' => true,
                    'subscription_id' => $subscriptionId,
                ],
            ],
        ]);

        // Record subscription start
        $this->monitoringService->recordMetric('websocket.subscription.started', 1, [
            'subscription_id' => $subscriptionId,
            'connection_id' => $conn->resourceId,
            'user_id' => $metadata['user_id'],
            'subscription_type' => $subscription['type'],
            'subscription_field' => $subscription['field'],
        ]);
    }

    /**
     * Handle subscription stop
     */
    protected function handleSubscriptionStop(ConnectionInterface $conn, array $message): void
    {
        $subscriptionId = $message['id'] ?? null;
        
        if (!$subscriptionId) {
            throw new \Exception('Subscription ID is required');
        }

        if (isset($this->subscriptions[$subscriptionId])) {
            $subscription = $this->subscriptions[$subscriptionId];
            
            // Remove subscription
            unset($this->subscriptions[$subscriptionId]);
            
            // Remove from connection metadata
            $metadata = &$this->connectionMetadata[$conn->resourceId];
            $metadata['subscriptions'] = array_filter(
                $metadata['subscriptions'],
                fn($id) => $id !== $subscriptionId
            );

            // Send confirmation
            $this->sendMessage($conn, [
                'type' => 'complete',
                'id' => $subscriptionId,
            ]);

            // Record subscription stop
            $this->monitoringService->recordMetric('websocket.subscription.stopped', 1, [
                'subscription_id' => $subscriptionId,
                'connection_id' => $conn->resourceId,
                'user_id' => $subscription['user_id'],
                'duration_seconds' => now()->diffInSeconds($subscription['created_at']),
            ]);
        }
    }

    /**
     * Handle connection termination
     */
    protected function handleConnectionTerminate(ConnectionInterface $conn): void
    {
        $conn->close(1000, 'Connection terminated by client');
    }

    /**
     * Handle ping message
     */
    protected function handlePing(ConnectionInterface $conn, array $message): void
    {
        $this->sendMessage($conn, [
            'type' => 'pong',
            'payload' => $message['payload'] ?? null,
        ]);
    }

    /**
     * Handle pong message
     */
    protected function handlePong(ConnectionInterface $conn, array $message): void
    {
        // Update connection activity (already done in onMessage)
        // Could add additional latency tracking here
    }

    /**
     * Broadcast data to subscription
     */
    public function broadcastToSubscription(string $subscriptionType, array $data, ?int $tenantId = null): void
    {
        $matchingSubscriptions = array_filter(
            $this->subscriptions,
            function ($subscription) use ($subscriptionType, $tenantId) {
                return $subscription['subscription_type'] === $subscriptionType &&
                       ($tenantId === null || $subscription['tenant_id'] === $tenantId);
            }
        );

        foreach ($matchingSubscriptions as $subscription) {
            try {
                $this->sendMessage($subscription['connection'], [
                    'type' => 'data',
                    'id' => $subscription['id'],
                    'payload' => ['data' => $data],
                ]);

                // Update subscription activity
                $this->subscriptions[$subscription['id']]['last_activity'] = now()->toISOString();

            } catch (\Exception $e) {
                // Remove failed subscription
                $this->removeSubscription($subscription['id']);
                
                Log::warning("Failed to broadcast to subscription", [
                    'subscription_id' => $subscription['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send message to connection
     */
    protected function sendMessage(ConnectionInterface $conn, array $message): void
    {
        $conn->send(json_encode($message));
    }

    /**
     * Send error message
     */
    protected function sendError(ConnectionInterface $conn, string $error, ?string $subscriptionId = null): void
    {
        $message = [
            'type' => 'error',
            'payload' => [
                'message' => $error,
                'timestamp' => now()->toISOString(),
            ],
        ];

        if ($subscriptionId) {
            $message['id'] = $subscriptionId;
        }

        $this->sendMessage($conn, $message);
    }

    /**
     * Authenticate WebSocket connection
     */
    protected function authenticateConnection(string $authToken): ?\App\Models\User
    {
        // Extract token (remove "Bearer " prefix if present)
        $token = str_replace('Bearer ', '', $authToken);
        
        // Validate token and get user
        // This would integrate with your authentication system
        return Auth::guard('api')->user();
    }

    /**
     * Authorize subscription
     */
    protected function authorizeSubscription(?object $user, array $payload): bool
    {
        // Parse subscription to determine what's being requested
        $subscription = $this->parseGraphQLSubscription($payload);
        
        // Apply authorization rules
        return $this->securityService->canSubscribeToField(
            $user,
            $subscription['field'],
            $subscription['arguments']
        );
    }

    /**
     * Parse GraphQL subscription
     */
    protected function parseGraphQLSubscription(array $payload): array
    {
        // Simplified GraphQL parsing - in production, use a proper GraphQL parser
        $query = $payload['query'];
        
        // Extract subscription field and arguments
        // This is a simplified implementation
        preg_match('/subscription\s*{?\s*(\w+)\s*(\([^)]*\))?\s*{/', $query, $matches);
        
        $field = $matches[1] ?? 'unknown';
        $args = $matches[2] ?? '';
        
        return [
            'type' => 'subscription',
            'field' => $field,
            'arguments' => $this->parseArguments($args),
        ];
    }

    /**
     * Parse GraphQL arguments
     */
    protected function parseArguments(string $argsString): array
    {
        // Simplified argument parsing
        // In production, use a proper GraphQL argument parser
        $args = [];
        
        if (preg_match_all('/(\w+):\s*([^,)]+)/', $argsString, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $args[$match[1]] = trim($match[2], '"\'');
            }
        }
        
        return $args;
    }

    /**
     * Check subscription rate limit
     */
    protected function checkSubscriptionRateLimit(?object $user, array $subscription): bool
    {
        $key = $user 
            ? "websocket.subscription.user.{$user->id}" 
            : "websocket.subscription.anonymous";
            
        $limit = $user ? 50 : 5; // Different limits for authenticated vs anonymous
        
        $current = Cache::get($key, 0);
        
        if ($current >= $limit) {
            return false;
        }
        
        Cache::put($key, $current + 1, 3600); // 1 hour window
        
        return true;
    }

    /**
     * Update connection activity timestamp
     */
    protected function updateConnectionActivity(ConnectionInterface $conn): void
    {
        if (isset($this->connectionMetadata[$conn->resourceId])) {
            $this->connectionMetadata[$conn->resourceId]['last_activity'] = now()->toISOString();
        }
    }

    /**
     * Clean up subscriptions for a connection
     */
    protected function cleanupConnectionSubscriptions(ConnectionInterface $conn): void
    {
        $connectionId = $conn->resourceId;
        $metadata = $this->connectionMetadata[$connectionId] ?? [];
        $subscriptionIds = $metadata['subscriptions'] ?? [];

        foreach ($subscriptionIds as $subscriptionId) {
            if (isset($this->subscriptions[$subscriptionId])) {
                unset($this->subscriptions[$subscriptionId]);
            }
        }
    }

    /**
     * Remove specific subscription
     */
    protected function removeSubscription(string $subscriptionId): void
    {
        if (isset($this->subscriptions[$subscriptionId])) {
            $subscription = $this->subscriptions[$subscriptionId];
            $connectionId = $subscription['connection_id'];
            
            unset($this->subscriptions[$subscriptionId]);
            
            // Remove from connection metadata
            if (isset($this->connectionMetadata[$connectionId])) {
                $this->connectionMetadata[$connectionId]['subscriptions'] = array_filter(
                    $this->connectionMetadata[$connectionId]['subscriptions'],
                    fn($id) => $id !== $subscriptionId
                );
            }
        }
    }

    /**
     * Get connection IP address
     */
    protected function getConnectionIP(ConnectionInterface $conn): string
    {
        return $conn->remoteAddress ?? 'unknown';
    }

    /**
     * Get connection user agent
     */
    protected function getConnectionUserAgent(ConnectionInterface $conn): string
    {
        // Extract from headers if available
        return $conn->httpRequest->getHeader('User-Agent')[0] ?? 'unknown';
    }

    /**
     * Get connection statistics
     */
    public function getConnectionStats(): array
    {
        return [
            'total_connections' => $this->connections->count(),
            'total_subscriptions' => count($this->subscriptions),
            'authenticated_connections' => count(array_filter(
                $this->connectionMetadata,
                fn($meta) => $meta['authentication_status'] === 'authenticated'
            )),
            'subscription_types' => array_count_values(
                array_column($this->subscriptions, 'subscription_type')
            ),
        ];
    }
}