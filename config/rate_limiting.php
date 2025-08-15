<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Advanced Rate Limiting Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for sophisticated rate limiting with multiple algorithms,
    | dynamic adjustment, and comprehensive security features.
    |
    */

    // Default rate limiting settings
    'default_requests_per_minute' => env('RATE_LIMIT_DEFAULT_RPM', 100),
    'default_burst_size' => env('RATE_LIMIT_DEFAULT_BURST', 20),
    'default_algorithm' => env('RATE_LIMIT_ALGORITHM', 'sliding_window'),
    'default_window_size' => env('RATE_LIMIT_WINDOW_SIZE', 60), // seconds

    // Available algorithms: token_bucket, sliding_window, fixed_window, leaky_bucket
    'algorithms' => [
        'token_bucket' => [
            'name' => 'Token Bucket',
            'description' => 'Allows bursts up to bucket size with steady refill rate',
            'best_for' => 'APIs with bursty traffic patterns',
            'parameters' => ['requests_per_minute', 'burst_size']
        ],
        'sliding_window' => [
            'name' => 'Sliding Window',
            'description' => 'Precise rate limiting with rolling time window',
            'best_for' => 'Strict rate enforcement',
            'parameters' => ['requests_per_minute', 'window_size']
        ],
        'fixed_window' => [
            'name' => 'Fixed Window',
            'description' => 'Simple counter reset at fixed intervals',
            'best_for' => 'High-performance scenarios',
            'parameters' => ['requests_per_minute', 'window_size']
        ],
        'leaky_bucket' => [
            'name' => 'Leaky Bucket',
            'description' => 'Smooth traffic shaping with constant output rate',
            'best_for' => 'Traffic smoothing and queue management',
            'parameters' => ['requests_per_minute', 'burst_size']
        ],
    ],

    // Dynamic rate adjustment based on system load
    'dynamic_adjustment' => [
        'enabled' => env('RATE_LIMIT_DYNAMIC', true),
        'load_thresholds' => [
            'low' => 0.2,      // < 20% load: increase limits by 50%
            'medium' => 0.6,   // 20-60% load: normal limits
            'high' => 0.8,     // 60-80% load: reduce limits by 25%
            'critical' => 0.9, // > 80% load: reduce limits by 50%
        ],
        'adjustment_factors' => [
            'low' => 1.5,
            'medium' => 1.0,
            'high' => 0.75,
            'critical' => 0.5,
        ],
        'check_interval' => 30, // seconds
    ],

    // Client identification and grouping
    'client_identification' => [
        'priority' => ['api_key', 'user_id', 'ip_address'],
        'api_key_header' => 'X-API-Key',
        'consider_forwarded_ips' => true,
        'proxy_headers' => ['X-Forwarded-For', 'X-Real-IP'],
    ],

    // Per-route rate limiting configuration
    'routes' => [
        'api/v1/orchestration/*' => [
            'requests_per_minute' => 200,
            'burst_size' => 50,
            'algorithm' => 'token_bucket',
        ],
        'api/v1/agents/*' => [
            'requests_per_minute' => 300,
            'burst_size' => 75,
            'algorithm' => 'sliding_window',
        ],
        'webhooks/*' => [
            'requests_per_minute' => 50,
            'burst_size' => 10,
            'algorithm' => 'leaky_bucket',
        ],
        'api/v1/security/*' => [
            'requests_per_minute' => 100,
            'burst_size' => 20,
            'algorithm' => 'fixed_window',
        ],
    ],

    // Client type specific limits
    'client_types' => [
        'admin' => [
            'requests_per_minute' => 1000,
            'burst_size' => 200,
            'priority' => 'high',
        ],
        'service' => [
            'requests_per_minute' => 500,
            'burst_size' => 100,
            'priority' => 'medium',
        ],
        'user' => [
            'requests_per_minute' => 100,
            'burst_size' => 25,
            'priority' => 'normal',
        ],
        'anonymous' => [
            'requests_per_minute' => 20,
            'burst_size' => 5,
            'priority' => 'low',
        ],
    ],

    // Violation handling
    'violations' => [
        'max_violations_before_block' => env('RATE_LIMIT_MAX_VIOLATIONS', 10),
        'block_duration_seconds' => env('RATE_LIMIT_BLOCK_DURATION', 300), // 5 minutes
        'progressive_penalties' => [
            5 => 60,    // 5 violations: 1 minute block
            10 => 300,  // 10 violations: 5 minute block
            20 => 900,  // 20 violations: 15 minute block
            50 => 3600, // 50 violations: 1 hour block
        ],
        'reset_violations_after' => 3600, // Reset violation count after 1 hour
    ],

    // Whitelist and bypass rules
    'bypass' => [
        'skip_in_development' => env('RATE_LIMIT_SKIP_DEV', false),
        'skip_paths' => [
            '/health',
            '/status',
            '/metrics',
        ],
        'skip_ips' => [
            '127.0.0.1',
            '::1',
            // Add monitoring system IPs here
        ],
        'admin_bypass_header' => 'X-Admin-Bypass',
        'admin_bypass_token' => env('RATE_LIMIT_ADMIN_BYPASS_TOKEN'),
    ],

    // Headers and responses
    'response_headers' => [
        'include_headers' => true,
        'header_prefix' => 'X-RateLimit-',
        'include_algorithm' => true,
        'include_retry_after' => true,
    ],

    'response_format' => [
        'error_message' => 'Rate limit exceeded',
        'include_limits' => true,
        'include_retry_after' => true,
        'custom_error_handler' => null, // Callable for custom error responses
    ],

    // Storage and caching
    'storage' => [
        'driver' => env('RATE_LIMIT_STORAGE', 'redis'), // redis, database, memory
        'redis_connection' => 'default',
        'database_table' => 'rate_limits',
        'key_prefix' => 'rate_limit:',
        'cleanup_interval' => 3600, // Clean up expired entries every hour
    ],

    // Monitoring and metrics
    'monitoring' => [
        'enabled' => env('RATE_LIMIT_MONITORING', true),
        'log_successful_requests' => false,
        'log_blocked_requests' => true,
        'metrics' => [
            'requests_total' => true,
            'requests_blocked' => true,
            'response_times' => true,
            'algorithm_performance' => true,
        ],
        'alerts' => [
            'high_block_rate' => 0.1, // Alert if > 10% requests blocked
            'system_overload' => 0.8,  // Alert if system load > 80%
            'client_abuse' => 100,     // Alert if client exceeds 100 req/min
        ],
    ],

    // Security features
    'security' => [
        'detect_distributed_attacks' => true,
        'ip_reputation_checking' => true,
        'geolocation_blocking' => [
            'enabled' => false,
            'blocked_countries' => [], // ISO country codes
            'allowed_countries' => [], // If set, only these are allowed
        ],
        'bot_detection' => [
            'enabled' => true,
            'user_agent_patterns' => [
                '/bot/i',
                '/crawler/i',
                '/spider/i',
                '/scraper/i',
            ],
            'rate_limit_bots' => true,
            'bot_limit_factor' => 0.1, // Bots get 10% of normal limits
        ],
    ],

    // Circuit breaker integration
    'circuit_breaker' => [
        'enabled' => true,
        'failure_threshold' => 50, // Trip after 50% of requests are rate limited
        'recovery_timeout' => 300, // 5 minutes
        'half_open_requests' => 10, // Allow 10 requests in half-open state
    ],

    // Enhanced Rate Limiting Configuration
    'enhanced' => [
        'enabled' => env('RATE_LIMIT_ENHANCED', true),
        'base_limits' => [
            'requests_per_minute' => env('RATE_LIMIT_BASE_RPM', 100),
            'burst_size' => env('RATE_LIMIT_BASE_BURST', 20),
            'window_size' => env('RATE_LIMIT_BASE_WINDOW', 60),
        ],
        'endpoint_multipliers' => [
            'orchestration' => [
                'requests_multiplier' => 2.0,
                'burst_multiplier' => 2.5,
            ],
            'agents' => [
                'requests_multiplier' => 3.0,
                'burst_multiplier' => 3.0,
            ],
            'security' => [
                'requests_multiplier' => 1.0,
                'burst_multiplier' => 1.0,
            ],
            'webhooks' => [
                'requests_multiplier' => 0.5,
                'burst_multiplier' => 0.5,
            ],
            'api' => [
                'requests_multiplier' => 1.5,
                'burst_multiplier' => 1.5,
            ],
            'web' => [
                'requests_multiplier' => 1.0,
                'burst_multiplier' => 1.0,
            ],
        ],
        'endpoint_protection' => [
            'security' => [
                'requests_per_minute' => 50,
                'burst_size' => 10,
                'additional_checks' => true,
            ],
            'webhooks' => [
                'requests_per_minute' => 30,
                'burst_size' => 5,
                'signature_verification' => true,
            ],
        ],
    ],

    // Threat Detection Configuration
    'threat_detection' => [
        'enabled' => env('THREAT_DETECTION_ENABLED', true),
        'ddos_threshold' => env('THREAT_DDOS_THRESHOLD', 1000),
        'distributed_threshold' => env('THREAT_DISTRIBUTED_THRESHOLD', 5000),
        'bot_score_threshold' => env('THREAT_BOT_THRESHOLD', 0.8),
        'anomaly_threshold' => env('THREAT_ANOMALY_THRESHOLD', 3.0),
        'reputation_cache_ttl' => env('THREAT_REPUTATION_TTL', 3600),
        'pattern_window' => env('THREAT_PATTERN_WINDOW', 300),
        'ip_reputation_sources' => [
            'abuseipdb' => [
                'enabled' => false,
                'api_key' => env('ABUSEIPDB_API_KEY'),
                'confidence_threshold' => 75,
            ],
            'virustotal' => [
                'enabled' => false,
                'api_key' => env('VIRUSTOTAL_API_KEY'),
                'detection_threshold' => 3,
            ],
        ],
    ],

    // Advanced features
    'advanced' => [
        'adaptive_limits' => [
            'enabled' => env('RATE_LIMIT_ADAPTIVE', false),
            'learning_period' => 7, // days
            'adjustment_frequency' => 24, // hours
            'max_adjustment' => 0.5, // 50% max change
        ],
        'predictive_scaling' => [
            'enabled' => env('RATE_LIMIT_PREDICTIVE', false),
            'historical_data_days' => 30,
            'prediction_horizon_hours' => 24,
        ],
        'quota_management' => [
            'enabled' => env('RATE_LIMIT_QUOTAS', false),
            'daily_quotas' => true,
            'monthly_quotas' => true,
            'quota_reset_timezone' => 'UTC',
        ],
        'request_queuing' => [
            'enabled' => env('RATE_LIMIT_QUEUING', true),
            'max_queue_time' => 300, // 5 minutes
            'priority_processing' => true,
            'queue_overflow_action' => 'reject', // reject, redirect
        ],
    ],

    // Testing and debugging
    'testing' => [
        'test_mode' => env('RATE_LIMIT_TEST_MODE', false),
        'test_multiplier' => 10, // 10x faster rate limiting for testing
        'debug_headers' => env('APP_DEBUG', false),
        'dry_run' => false, // Log but don't actually block
    ],
];