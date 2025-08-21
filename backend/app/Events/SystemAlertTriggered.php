<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\SystemAlert;

/**
 * System Alert Triggered Event
 * 
 * Fired when a new system alert is created
 * Enables real-time notifications and automated responses
 */
class SystemAlertTriggered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public SystemAlert $alert;

    /**
     * Create a new event instance
     */
    public function __construct(SystemAlert $alert)
    {
        $this->alert = $alert;
    }

    /**
     * Get the channels the event should broadcast on
     */
    public function broadcastOn(): array
    {
        $channels = [
            new Channel('system-alerts'), // Global system alerts channel
        ];

        // Add tenant-specific channel if alert is tenant-scoped
        if ($this->alert->tenant_id) {
            $channels[] = new PrivateChannel("tenant.{$this->alert->tenant_id}.alerts");
        }

        // Add severity-specific channel for critical alerts
        if ($this->alert->severity === 'critical') {
            $channels[] = new Channel('critical-alerts');
        }

        return $channels;
    }

    /**
     * Get the data to broadcast
     */
    public function broadcastWith(): array
    {
        return [
            'alert' => [
                'id' => $this->alert->id,
                'type' => $this->alert->type,
                'severity' => $this->alert->severity,
                'message' => $this->alert->message,
                'status' => $this->alert->status,
                'context' => $this->alert->context,
                'tenant_id' => $this->alert->tenant_id,
                'trace_id' => $this->alert->trace_id,
                'created_at' => $this->alert->created_at->toISOString(),
            ],
            'metadata' => [
                'timestamp' => now()->toISOString(),
                'broadcast_channels' => $this->getBroadcastChannels(),
                'priority_score' => $this->alert->getPriorityScore(),
            ],
        ];
    }

    /**
     * The event's broadcast name
     */
    public function broadcastAs(): string
    {
        return 'alert.triggered';
    }

    /**
     * Determine if this event should be broadcast
     */
    public function broadcastWhen(): bool
    {
        // Only broadcast if alert is active and not suppressed
        return $this->alert->status === SystemAlert::STATUS_ACTIVE;
    }

    /**
     * Get the broadcast channels as an array of strings
     */
    protected function getBroadcastChannels(): array
    {
        return array_map(function ($channel) {
            if ($channel instanceof Channel) {
                return $channel->name;
            }
            if ($channel instanceof PrivateChannel) {
                return 'private-' . $channel->name;
            }
            if ($channel instanceof PresenceChannel) {
                return 'presence-' . $channel->name;
            }
            return (string) $channel;
        }, $this->broadcastOn());
    }

    /**
     * Get alert escalation level
     */
    public function getEscalationLevel(): string
    {
        $context = $this->alert->context ?? [];
        $escalationRules = [
            'critical' => 'immediate',
            'warning' => 'standard',
            'info' => 'low',
        ];

        // Check for custom escalation rules in context
        if (isset($context['escalation_level'])) {
            return $context['escalation_level'];
        }

        return $escalationRules[$this->alert->severity] ?? 'standard';
    }

    /**
     * Get notification channels for this alert
     */
    public function getNotificationChannels(): array
    {
        $channels = ['database']; // Always log to database

        // Add channels based on severity
        switch ($this->alert->severity) {
            case 'critical':
                $channels = array_merge($channels, ['email', 'sms', 'slack', 'webhook']);
                break;
            case 'warning':
                $channels = array_merge($channels, ['email', 'slack']);
                break;
            case 'info':
                $channels = array_merge($channels, ['slack']);
                break;
        }

        // Add tenant-specific channels if configured
        if ($this->alert->tenant_id && $this->alert->tenant) {
            $tenantChannels = $this->alert->tenant->monitoring_config['notification_channels'] ?? [];
            $channels = array_merge($channels, $tenantChannels);
        }

        return array_unique($channels);
    }

    /**
     * Check if alert should trigger automated response
     */
    public function shouldTriggerAutomatedResponse(): bool
    {
        $context = $this->alert->context ?? [];
        
        // Check if automated response is enabled
        if (isset($context['automated_response']) && $context['automated_response'] === false) {
            return false;
        }

        // Only trigger for critical alerts by default
        return $this->alert->severity === 'critical';
    }

    /**
     * Get automated response actions
     */
    public function getAutomatedResponseActions(): array
    {
        $context = $this->alert->context ?? [];
        $actions = [];

        // Default actions based on alert type
        switch ($this->alert->type) {
            case 'high_error_rate':
                $actions[] = [
                    'type' => 'throttle_requests',
                    'parameters' => ['rate_limit' => '50%'],
                ];
                break;
                
            case 'slow_response':
                $actions[] = [
                    'type' => 'scale_resources',
                    'parameters' => ['scale_factor' => 1.5],
                ];
                break;
                
            case 'external_service_failure':
                $actions[] = [
                    'type' => 'enable_circuit_breaker',
                    'parameters' => ['service' => $context['service'] ?? 'unknown'],
                ];
                break;
                
            case 'database_connection_limit':
                $actions[] = [
                    'type' => 'restart_connection_pool',
                    'parameters' => [],
                ];
                break;
        }

        // Add custom actions from context
        if (isset($context['automated_actions'])) {
            $actions = array_merge($actions, $context['automated_actions']);
        }

        return $actions;
    }

    /**
     * Get alert correlation data for grouping similar alerts
     */
    public function getCorrelationData(): array
    {
        return [
            'type' => $this->alert->type,
            'severity' => $this->alert->severity,
            'tenant_id' => $this->alert->tenant_id,
            'source_component' => $this->alert->context['component'] ?? 'unknown',
            'error_signature' => $this->generateErrorSignature(),
            'time_window' => now()->format('Y-m-d H:i'), // Group by minute
        ];
    }

    /**
     * Generate error signature for correlation
     */
    protected function generateErrorSignature(): string
    {
        $context = $this->alert->context ?? [];
        
        $signatureParts = [
            $this->alert->type,
            $context['endpoint'] ?? '',
            $context['error_code'] ?? '',
            $context['service'] ?? '',
        ];

        return hash('sha256', implode('|', array_filter($signatureParts)));
    }

    /**
     * Check if this is a duplicate alert
     */
    public function isDuplicate(): bool
    {
        $correlationData = $this->getCorrelationData();
        
        return SystemAlert::where('type', $correlationData['type'])
            ->where('status', SystemAlert::STATUS_ACTIVE)
            ->where('tenant_id', $correlationData['tenant_id'])
            ->where('created_at', '>=', now()->subMinutes(15))
            ->whereJsonContains('context->error_signature', $correlationData['error_signature'])
            ->where('id', '!=', $this->alert->id)
            ->exists();
    }

    /**
     * Get alert impact assessment
     */
    public function getImpactAssessment(): array
    {
        $context = $this->alert->context ?? [];
        
        return [
            'affected_users' => $context['affected_users'] ?? 'unknown',
            'affected_services' => $context['affected_services'] ?? [],
            'business_impact' => $this->assessBusinessImpact(),
            'recovery_time_estimate' => $this->estimateRecoveryTime(),
            'mitigation_steps' => $this->getMitigationSteps(),
        ];
    }

    /**
     * Assess business impact of the alert
     */
    protected function assessBusinessImpact(): string
    {
        switch ($this->alert->severity) {
            case 'critical':
                return 'high';
            case 'warning':
                return 'medium';
            default:
                return 'low';
        }
    }

    /**
     * Estimate recovery time based on alert type and severity
     */
    protected function estimateRecoveryTime(): string
    {
        $estimateMap = [
            'critical' => [
                'database_connection_failure' => '5-15 minutes',
                'external_service_failure' => '15-30 minutes',
                'high_error_rate' => '10-20 minutes',
                'default' => '15-30 minutes',
            ],
            'warning' => [
                'slow_response' => '10-30 minutes',
                'resource_usage_high' => '30-60 minutes',
                'default' => '30-60 minutes',
            ],
            'info' => [
                'default' => '1-4 hours',
            ],
        ];

        $severityMap = $estimateMap[$this->alert->severity] ?? $estimateMap['info'];
        return $severityMap[$this->alert->type] ?? $severityMap['default'];
    }

    /**
     * Get suggested mitigation steps
     */
    protected function getMitigationSteps(): array
    {
        $steps = [];
        $context = $this->alert->context ?? [];

        switch ($this->alert->type) {
            case 'high_error_rate':
                $steps = [
                    'Check application logs for specific error patterns',
                    'Verify external service dependencies',
                    'Consider temporarily reducing traffic load',
                    'Review recent deployments for potential issues',
                ];
                break;
                
            case 'slow_response':
                $steps = [
                    'Monitor database query performance',
                    'Check for resource bottlenecks (CPU, memory)',
                    'Review cache hit rates',
                    'Consider scaling application instances',
                ];
                break;
                
            case 'external_service_failure':
                $serviceName = $context['service'] ?? 'unknown service';
                $steps = [
                    "Check {$serviceName} service status",
                    'Verify network connectivity',
                    'Review API rate limits and quotas',
                    'Consider enabling fallback mechanisms',
                ];
                break;
                
            default:
                $steps = [
                    'Review alert details and context',
                    'Check system logs for related events',
                    'Monitor related metrics',
                    'Escalate if issue persists',
                ];
        }

        return $steps;
    }
}