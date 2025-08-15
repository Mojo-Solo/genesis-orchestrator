<?php

namespace App\Connectors;

use App\Contracts\APIConnectorInterface;
use App\Services\EnhancedRateLimitService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Exception;

class SlackConnector implements APIConnectorInterface
{
    protected $config;
    protected $rateLimitService;
    protected $baseUrl = 'https://slack.com/api';

    public function __construct(array $config, EnhancedRateLimitService $rateLimitService)
    {
        $this->config = $config;
        $this->rateLimitService = $rateLimitService;
    }

    public function getDisplayName(): string
    {
        return 'Slack';
    }

    public function getDescription(): string
    {
        return 'Connect with Slack to send messages, manage channels, and receive real-time notifications from your workspace.';
    }

    public function getCategory(): string
    {
        return 'Communication';
    }

    public function getCapabilities(): array
    {
        return [
            'send_messages',
            'create_channels',
            'manage_users',
            'file_upload',
            'webhook_notifications',
            'bulk_messaging',
            'channel_management',
            'user_presence',
        ];
    }

    public function getAuthType(): string
    {
        return 'oauth2';
    }

    public function requiresConfiguration(): bool
    {
        return true;
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function supportsBulkOperations(): bool
    {
        return true;
    }

    public function validateConfiguration(array $configuration): array
    {
        $errors = [];

        if (empty($configuration['access_token'])) {
            $errors[] = 'Access token is required';
        }

        if (empty($configuration['workspace_id'])) {
            $errors[] = 'Workspace ID is required';
        }

        if (isset($configuration['webhook_url']) && !filter_var($configuration['webhook_url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'Invalid webhook URL format';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    public function testConnection(array $configuration): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $configuration['access_token'],
                'Content-Type' => 'application/json',
            ])->get($this->baseUrl . '/auth.test');

            if ($response->successful()) {
                $data = $response->json();
                
                if ($data['ok']) {
                    return [
                        'success' => true,
                        'message' => 'Successfully connected to Slack',
                        'workspace' => $data['team'] ?? 'Unknown',
                        'user' => $data['user'] ?? 'Unknown',
                    ];
                } else {
                    return [
                        'success' => false,
                        'error' => $data['error'] ?? 'Unknown error',
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'error' => 'HTTP ' . $response->status() . ': ' . $response->body(),
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Connection failed: ' . $e->getMessage(),
            ];
        }
    }

    public function initialize(string $tenantId, array $configuration): void
    {
        // Store any initialization data if needed
        Cache::put("slack_init_{$tenantId}", [
            'workspace_id' => $configuration['workspace_id'],
            'initialized_at' => Carbon::now(),
        ], 86400); // 24 hours
    }

    public function executeCall(string $method, string $endpoint, array $data, array $configuration, array $options = []): array
    {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        
        $headers = [
            'Authorization' => 'Bearer ' . $configuration['access_token'],
            'Content-Type' => 'application/json',
        ];

        // Apply rate limiting
        $rateLimitKey = "slack_api:" . $configuration['workspace_id'];
        if (!$this->rateLimitService->attempt($rateLimitKey, $this->getRateLimits())) {
            throw new Exception('Slack API rate limit exceeded');
        }

        try {
            $httpClient = Http::withHeaders($headers)
                ->timeout($options['timeout'] ?? 30);

            switch (strtoupper($method)) {
                case 'GET':
                    $response = $httpClient->get($url, $data);
                    break;
                case 'POST':
                    $response = $httpClient->post($url, $data);
                    break;
                case 'PUT':
                    $response = $httpClient->put($url, $data);
                    break;
                case 'DELETE':
                    $response = $httpClient->delete($url, $data);
                    break;
                default:
                    throw new Exception("Unsupported HTTP method: {$method}");
            }

            $responseData = $response->json();

            if ($response->successful() && $responseData['ok']) {
                return [
                    'success' => true,
                    'data' => $responseData,
                    'status_code' => $response->status(),
                ];
            } else {
                throw new Exception($responseData['error'] ?? 'Slack API error');
            }

        } catch (Exception $e) {
            Log::error('Slack API call failed', [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function getWebhookConfiguration(string $tenantId, string $webhookUrl): array
    {
        return [
            'webhook_url' => $webhookUrl,
            'setup_instructions' => [
                'Go to your Slack app settings',
                'Navigate to Event Subscriptions',
                'Enable events and add the webhook URL: ' . $webhookUrl,
                'Subscribe to the events you want to receive',
                'Save your changes',
            ],
            'verification_token_required' => true,
            'signing_secret_required' => true,
        ];
    }

    public function getSupportedWebhookEvents(): array
    {
        return [
            'message' => 'Message posted in channel',
            'app_mention' => 'App mentioned in message',
            'channel_created' => 'Channel created',
            'channel_deleted' => 'Channel deleted',
            'member_joined_channel' => 'Member joined channel',
            'member_left_channel' => 'Member left channel',
            'user_change' => 'User profile changed',
            'team_join' => 'New user joined workspace',
        ];
    }

    public function verifyWebhookSignature(string $payload, string $signature, array $headers): bool
    {
        $timestamp = $headers['X-Slack-Request-Timestamp'] ?? '';
        $signingSecret = $this->config['signing_secret'] ?? '';

        if (empty($signingSecret) || empty($timestamp)) {
            return false;
        }

        // Check timestamp to prevent replay attacks (within 5 minutes)
        if (abs(time() - intval($timestamp)) > 300) {
            return false;
        }

        $baseString = 'v0:' . $timestamp . ':' . $payload;
        $expectedSignature = 'v0=' . hash_hmac('sha256', $baseString, $signingSecret);

        return hash_equals($expectedSignature, $signature);
    }

    public function parseWebhookPayload(string $payload, array $headers): array
    {
        $data = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON payload');
        }

        // Handle URL verification challenge
        if (isset($data['type']) && $data['type'] === 'url_verification') {
            return [
                'event_type' => 'url_verification',
                'challenge' => $data['challenge'],
                'needs_response' => true,
            ];
        }

        // Parse regular webhook event
        return [
            'event_type' => $data['event']['type'] ?? 'unknown',
            'team_id' => $data['team_id'] ?? null,
            'api_app_id' => $data['api_app_id'] ?? null,
            'event_data' => $data['event'] ?? [],
            'event_id' => $data['event_id'] ?? null,
            'event_time' => $data['event_time'] ?? time(),
            'needs_response' => false,
        ];
    }

    public function processWebhook(string $tenantId, array $webhookData, array $configuration): array
    {
        switch ($webhookData['event_type']) {
            case 'url_verification':
                return [
                    'response' => $webhookData['challenge'],
                    'processed' => true,
                ];

            case 'message':
                return $this->processMessageEvent($tenantId, $webhookData, $configuration);

            case 'app_mention':
                return $this->processAppMentionEvent($tenantId, $webhookData, $configuration);

            case 'channel_created':
                return $this->processChannelCreatedEvent($tenantId, $webhookData, $configuration);

            default:
                Log::info('Unhandled Slack webhook event', [
                    'tenant_id' => $tenantId,
                    'event_type' => $webhookData['event_type'],
                ]);

                return [
                    'processed' => false,
                    'message' => 'Event type not handled',
                ];
        }
    }

    public function executeBulkOperation(string $operation, array $items, array $configuration, array $options = []): array
    {
        switch ($operation) {
            case 'send_messages':
                return $this->bulkSendMessages($items, $configuration, $options);
            
            case 'create_channels':
                return $this->bulkCreateChannels($items, $configuration, $options);
            
            case 'invite_users':
                return $this->bulkInviteUsers($items, $configuration, $options);
            
            default:
                throw new Exception("Unsupported bulk operation: {$operation}");
        }
    }

    public function cleanup(string $tenantId): void
    {
        // Clean up any cached data
        Cache::forget("slack_init_{$tenantId}");
        
        // Could also revoke tokens or clean up webhooks if needed
        Log::info('Slack connector cleanup completed', ['tenant_id' => $tenantId]);
    }

    public function getRateLimits(): array
    {
        return [
            'requests_per_minute' => 20,
            'burst_size' => 5,
        ];
    }

    public function getAvailableEndpoints(): array
    {
        return [
            'chat.postMessage' => 'Send a message to a channel',
            'conversations.create' => 'Create a new channel',
            'conversations.list' => 'List channels',
            'users.list' => 'List workspace users',
            'files.upload' => 'Upload a file',
            'auth.test' => 'Test authentication',
            'conversations.invite' => 'Invite users to channel',
            'conversations.join' => 'Join a channel',
            'chat.update' => 'Update a message',
            'chat.delete' => 'Delete a message',
        ];
    }

    public function getRequiredScopes(): array
    {
        return [
            'chat:write' => 'Send messages',
            'channels:read' => 'View channels',
            'users:read' => 'View users',
            'files:write' => 'Upload files',
            'channels:manage' => 'Create and manage channels',
        ];
    }

    /**
     * Helper methods for specific operations
     */
    protected function processMessageEvent(string $tenantId, array $webhookData, array $configuration): array
    {
        $eventData = $webhookData['event_data'];
        
        // Process the message event
        // This could trigger orchestration workflows, store messages, etc.
        
        return [
            'processed' => true,
            'message_id' => $eventData['ts'] ?? null,
            'channel' => $eventData['channel'] ?? null,
            'user' => $eventData['user'] ?? null,
        ];
    }

    protected function processAppMentionEvent(string $tenantId, array $webhookData, array $configuration): array
    {
        $eventData = $webhookData['event_data'];
        
        // Process app mention - could trigger auto-responses
        
        return [
            'processed' => true,
            'mentioned_in' => $eventData['channel'] ?? null,
            'text' => $eventData['text'] ?? null,
        ];
    }

    protected function processChannelCreatedEvent(string $tenantId, array $webhookData, array $configuration): array
    {
        $eventData = $webhookData['event_data'];
        
        // Process channel creation
        
        return [
            'processed' => true,
            'channel_id' => $eventData['channel']['id'] ?? null,
            'channel_name' => $eventData['channel']['name'] ?? null,
        ];
    }

    protected function bulkSendMessages(array $messages, array $configuration, array $options): array
    {
        $results = [];
        $successful = 0;
        $failed = 0;

        foreach ($messages as $index => $message) {
            try {
                $result = $this->executeCall('POST', 'chat.postMessage', $message, $configuration, $options);
                $results[$index] = ['success' => true, 'data' => $result];
                $successful++;
                
                // Add small delay to respect rate limits
                usleep(250000); // 250ms
                
            } catch (Exception $e) {
                $results[$index] = ['success' => false, 'error' => $e->getMessage()];
                $failed++;
            }
        }

        return [
            'total' => count($messages),
            'successful' => $successful,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    protected function bulkCreateChannels(array $channels, array $configuration, array $options): array
    {
        $results = [];
        $successful = 0;
        $failed = 0;

        foreach ($channels as $index => $channel) {
            try {
                $result = $this->executeCall('POST', 'conversations.create', $channel, $configuration, $options);
                $results[$index] = ['success' => true, 'data' => $result];
                $successful++;
                
                usleep(500000); // 500ms delay for channel creation
                
            } catch (Exception $e) {
                $results[$index] = ['success' => false, 'error' => $e->getMessage()];
                $failed++;
            }
        }

        return [
            'total' => count($channels),
            'successful' => $successful,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    protected function bulkInviteUsers(array $invitations, array $configuration, array $options): array
    {
        $results = [];
        $successful = 0;
        $failed = 0;

        foreach ($invitations as $index => $invitation) {
            try {
                $result = $this->executeCall('POST', 'conversations.invite', $invitation, $configuration, $options);
                $results[$index] = ['success' => true, 'data' => $result];
                $successful++;
                
                usleep(200000); // 200ms delay
                
            } catch (Exception $e) {
                $results[$index] = ['success' => false, 'error' => $e->getMessage()];
                $failed++;
            }
        }

        return [
            'total' => count($invitations),
            'successful' => $successful,
            'failed' => $failed,
            'results' => $results,
        ];
    }
}