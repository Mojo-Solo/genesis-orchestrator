<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\SystemAlert;

/**
 * Metric Threshold Exceeded Event
 * 
 * Fired when a monitored metric exceeds its configured threshold
 * Enables real-time alerting and automated remediation
 */
class MetricThresholdExceeded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public SystemAlert $alert;
    public string $metricName;
    public float $currentValue;
    public float $threshold;
    public string $operator;
    public int $duration;

    /**
     * Create a new event instance
     */
    public function __construct(
        SystemAlert $alert,
        string $metricName = null,
        float $currentValue = null,
        float $threshold = null,
        string $operator = null,
        int $duration = null
    ) {
        $this->alert = $alert;
        
        // Extract metric details from alert context if not provided
        $context = $alert->context ?? [];
        $this->metricName = $metricName ?? $context['metric'] ?? 'unknown';
        $this->currentValue = $currentValue ?? $context['value'] ?? 0;
        $this->threshold = $threshold ?? $context['threshold'] ?? 0;
        $this->operator = $operator ?? $context['operator'] ?? '>';
        $this->duration = $duration ?? $context['duration'] ?? 0;
    }

    /**
     * Get the channels the event should broadcast on
     */
    public function broadcastOn(): array
    {
        $channels = [
            new Channel('metric-alerts'), // Global metric alerts channel
            new Channel("metric-alerts.{$this->metricName}"), // Metric-specific channel
        ];

        // Add tenant-specific channel if alert is tenant-scoped
        if ($this->alert->tenant_id) {
            $channels[] = new PrivateChannel("tenant.{$this->alert->tenant_id}.metrics");
        }

        // Add severity-specific channel
        $channels[] = new Channel("metric-alerts.{$this->alert->severity}");

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
                'created_at' => $this->alert->created_at->toISOString(),
            ],
            'metric' => [
                'name' => $this->metricName,
                'current_value' => $this->currentValue,
                'threshold' => $this->threshold,
                'operator' => $this->operator,
                'duration_seconds' => $this->duration,
                'threshold_exceeded_by' => $this->calculateThresholdExcess(),
                'percentage_over_threshold' => $this->calculatePercentageOverThreshold(),
            ],
            'context' => [
                'tenant_id' => $this->alert->tenant_id,
                'trace_id' => $this->alert->trace_id,
                'timestamp' => now()->toISOString(),
                'remediation_actions' => $this->getRemediationActions(),
                'escalation_path' => $this->getEscalationPath(),
            ],
        ];
    }

    /**
     * The event's broadcast name
     */
    public function broadcastAs(): string
    {
        return 'metric.threshold.exceeded';
    }

    /**
     * Calculate how much the threshold was exceeded by
     */
    protected function calculateThresholdExcess(): float
    {
        switch ($this->operator) {
            case '>':
            case '>=':
                return max(0, $this->currentValue - $this->threshold);
            case '<':
            case '<=':
                return max(0, $this->threshold - $this->currentValue);
            default:
                return 0;
        }
    }

    /**
     * Calculate percentage over threshold
     */
    protected function calculatePercentageOverThreshold(): float
    {
        if ($this->threshold == 0) {
            return 0;
        }

        switch ($this->operator) {
            case '>':
            case '>=':
                return round((($this->currentValue - $this->threshold) / $this->threshold) * 100, 2);
            case '<':
            case '<=':
                return round((($this->threshold - $this->currentValue) / $this->threshold) * 100, 2);
            default:
                return 0;
        }
    }

    /**
     * Get appropriate remediation actions based on metric type
     */
    public function getRemediationActions(): array
    {
        $actions = [];

        switch ($this->metricName) {
            case 'api.response_time':
                $actions = [
                    [
                        'type' => 'scale_up',
                        'description' => 'Increase application instances',
                        'priority' => 'high',
                        'automated' => true,
                    ],
                    [
                        'type' => 'cache_warm',
                        'description' => 'Warm up application caches',
                        'priority' => 'medium',
                        'automated' => true,
                    ],
                    [
                        'type' => 'review_queries',
                        'description' => 'Review and optimize slow database queries',
                        'priority' => 'medium',
                        'automated' => false,
                    ],
                ];
                break;

            case 'api.error_rate':
                $actions = [
                    [
                        'type' => 'circuit_breaker',
                        'description' => 'Enable circuit breakers for failing services',
                        'priority' => 'critical',
                        'automated' => true,
                    ],
                    [
                        'type' => 'rollback',
                        'description' => 'Consider rolling back recent deployments',
                        'priority' => 'high',
                        'automated' => false,
                    ],
                    [
                        'type' => 'investigation',
                        'description' => 'Investigate error logs and traces',
                        'priority' => 'high',
                        'automated' => false,
                    ],
                ];
                break;

            case 'system.memory_usage':
                $actions = [
                    [
                        'type' => 'garbage_collection',
                        'description' => 'Force garbage collection',
                        'priority' => 'high',
                        'automated' => true,
                    ],
                    [
                        'type' => 'scale_up',
                        'description' => 'Increase memory allocation',
                        'priority' => 'high',
                        'automated' => true,
                    ],
                    [
                        'type' => 'memory_analysis',
                        'description' => 'Analyze memory usage patterns',
                        'priority' => 'medium',
                        'automated' => false,
                    ],
                ];
                break;

            case 'queue.depth':
                $actions = [
                    [
                        'type' => 'scale_workers',
                        'description' => 'Increase queue worker count',
                        'priority' => 'high',
                        'automated' => true,
                    ],
                    [
                        'type' => 'job_prioritization',
                        'description' => 'Prioritize critical jobs',
                        'priority' => 'medium',
                        'automated' => true,
                    ],
                    [
                        'type' => 'queue_analysis',
                        'description' => 'Analyze job processing patterns',
                        'priority' => 'medium',
                        'automated' => false,
                    ],
                ];
                break;

            case 'database.active_connections':
                $actions = [
                    [
                        'type' => 'connection_cleanup',
                        'description' => 'Clean up idle connections',
                        'priority' => 'high',
                        'automated' => true,
                    ],
                    [
                        'type' => 'connection_pool_increase',
                        'description' => 'Increase connection pool size',
                        'priority' => 'medium',
                        'automated' => true,
                    ],
                    [
                        'type' => 'query_optimization',
                        'description' => 'Optimize long-running queries',
                        'priority' => 'medium',
                        'automated' => false,
                    ],
                ];
                break;

            default:
                $actions = [
                    [
                        'type' => 'investigate',
                        'description' => 'Investigate metric threshold breach',
                        'priority' => 'medium',
                        'automated' => false,
                    ],
                    [
                        'type' => 'monitor',
                        'description' => 'Continue monitoring metric trends',
                        'priority' => 'low',
                        'automated' => true,
                    ],
                ];
        }

        // Add context-specific actions
        $context = $this->alert->context ?? [];
        if (isset($context['custom_actions'])) {
            $actions = array_merge($actions, $context['custom_actions']);
        }

        // Sort by priority
        usort($actions, function ($a, $b) {
            $priorityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
            return $priorityOrder[$a['priority']] <=> $priorityOrder[$b['priority']];
        });

        return $actions;
    }

    /**
     * Get escalation path for the metric alert
     */
    public function getEscalationPath(): array
    {
        $escalationLevels = [];

        // Level 1: Immediate automated response
        $escalationLevels[] = [
            'level' => 1,
            'trigger_after_minutes' => 0,
            'actions' => ['automated_remediation', 'team_notification'],
            'notification_channels' => ['slack', 'webhook'],
        ];

        // Level 2: Team escalation
        $escalationLevels[] = [
            'level' => 2,
            'trigger_after_minutes' => 5,
            'actions' => ['team_escalation', 'detailed_investigation'],
            'notification_channels' => ['email', 'sms', 'pagerduty'],
        ];

        // Level 3: Management escalation (for critical metrics)
        if ($this->alert->severity === 'critical') {
            $escalationLevels[] = [
                'level' => 3,
                'trigger_after_minutes' => 15,
                'actions' => ['management_escalation', 'incident_creation'],
                'notification_channels' => ['phone', 'executive_escalation'],
            ];
        }

        return $escalationLevels;
    }

    /**
     * Get metric trend analysis
     */
    public function getTrendAnalysis(): array
    {
        // This would typically analyze historical data for the metric
        // For now, return basic trend information
        return [
            'trend_direction' => $this->analyzeTrendDirection(),
            'historical_average' => $this->getHistoricalAverage(),
            'volatility_score' => $this->calculateVolatilityScore(),
            'prediction' => $this->getPrediction(),
        ];
    }

    /**
     * Analyze trend direction
     */
    protected function analyzeTrendDirection(): string
    {
        // Simplified trend analysis
        if ($this->currentValue > $this->threshold * 1.5) {
            return 'rapidly_increasing';
        } elseif ($this->currentValue > $this->threshold * 1.2) {
            return 'increasing';
        } elseif ($this->currentValue > $this->threshold) {
            return 'slightly_increasing';
        } else {
            return 'stable';
        }
    }

    /**
     * Get historical average for comparison
     */
    protected function getHistoricalAverage(): float
    {
        // This would calculate from historical data
        // For now, return estimated baseline
        return $this->threshold * 0.8;
    }

    /**
     * Calculate volatility score
     */
    protected function calculateVolatilityScore(): float
    {
        // Simplified volatility calculation
        $deviation = abs($this->currentValue - $this->getHistoricalAverage());
        $averageValue = ($this->currentValue + $this->getHistoricalAverage()) / 2;
        
        return $averageValue > 0 ? round(($deviation / $averageValue) * 100, 2) : 0;
    }

    /**
     * Get prediction for metric value
     */
    protected function getPrediction(): array
    {
        return [
            'next_5_minutes' => $this->currentValue * 1.05, // Simple linear prediction
            'next_15_minutes' => $this->currentValue * 1.1,
            'next_hour' => $this->currentValue * 1.2,
            'confidence' => 0.7, // 70% confidence in prediction
        ];
    }

    /**
     * Check if automated remediation should be triggered
     */
    public function shouldTriggerAutomatedRemediation(): bool
    {
        // Check if automated remediation is enabled
        $context = $this->alert->context ?? [];
        if (isset($context['automated_remediation']) && $context['automated_remediation'] === false) {
            return false;
        }

        // Only trigger for high-severity thresholds
        if ($this->alert->severity !== 'critical' && $this->alert->severity !== 'warning') {
            return false;
        }

        // Check if threshold is significantly exceeded
        $percentageOver = $this->calculatePercentageOverThreshold();
        return $percentageOver > 20; // 20% over threshold
    }

    /**
     * Get automated remediation commands
     */
    public function getAutomatedRemediationCommands(): array
    {
        $commands = [];
        $actions = $this->getRemediationActions();

        foreach ($actions as $action) {
            if ($action['automated'] ?? false) {
                $commands[] = [
                    'command' => $this->generateCommand($action),
                    'timeout_seconds' => 60,
                    'rollback_command' => $this->generateRollbackCommand($action),
                ];
            }
        }

        return $commands;
    }

    /**
     * Generate command for automated action
     */
    protected function generateCommand(array $action): string
    {
        switch ($action['type']) {
            case 'scale_up':
                return "kubectl scale deployment app --replicas=+2";
            case 'cache_warm':
                return "php artisan cache:warm";
            case 'garbage_collection':
                return "php artisan gc:force";
            case 'scale_workers':
                return "supervisorctl start queue:worker_{1,2,3}";
            case 'connection_cleanup':
                return "php artisan db:cleanup-connections";
            default:
                return "echo 'No automated command for {$action['type']}'";
        }
    }

    /**
     * Generate rollback command for automated action
     */
    protected function generateRollbackCommand(array $action): string
    {
        switch ($action['type']) {
            case 'scale_up':
                return "kubectl scale deployment app --replicas=-2";
            case 'scale_workers':
                return "supervisorctl stop queue:worker_{1,2,3}";
            default:
                return "echo 'No rollback needed for {$action['type']}'";
        }
    }
}