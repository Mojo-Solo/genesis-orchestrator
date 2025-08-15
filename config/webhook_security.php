<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Webhook Security Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for webhook security including HMAC validation,
    | source verification, and attack prevention mechanisms.
    |
    */

    'hmac_validation' => [
        'enabled' => env('WEBHOOK_HMAC_ENABLED', true),
        'required_in_production' => true,
        'default_algorithm' => 'sha256',
        'supported_algorithms' => ['sha1', 'sha256', 'sha512'],
        'timing_attack_protection' => true,
    ],

    'signature_headers' => [
        'primary' => 'X-Signature-256',
        'fallback' => [
            'X-Hub-Signature-256',
            'X-Signature',
            'X-Hub-Signature',
            'Signature',
        ],
        'algorithm_header' => 'X-Signature-Algorithm',
        'timestamp_header' => 'X-Timestamp',
    ],

    'replay_protection' => [
        'enabled' => env('WEBHOOK_REPLAY_PROTECTION', true),
        'max_timestamp_skew' => env('WEBHOOK_MAX_TIMESTAMP_SKEW', 300), // 5 minutes
        'require_timestamp' => env('WEBHOOK_REQUIRE_TIMESTAMP', false),
        'nonce_tracking' => [
            'enabled' => false,
            'window_seconds' => 3600, // 1 hour
            'storage' => 'redis', // redis, database, file
        ],
    ],

    'source_verification' => [
        'enabled' => true,
        'ip_whitelist' => [
            // GitHub webhook IPs
            '140.82.112.0/20',
            '185.199.108.0/22',
            '192.30.252.0/22',
            '143.55.64.0/20',
            
            // GitLab webhook IPs
            '35.231.145.151/32',
            '35.233.176.15/32',
            
            // Add your specific IPs here
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ],
        'user_agent_patterns' => [
            'GitHub-Hookshot/*',
            'GitLab/*',
            'Stripe/*',
            'Webhook/*',
        ],
    ],

    'rate_limiting' => [
        'enabled' => true,
        'requests_per_minute' => 100,
        'burst_size' => 20,
        'per_source' => true, // Rate limit per source IP/webhook
        'block_duration' => 300, // 5 minutes
    ],

    'payload_validation' => [
        'max_size' => 1048576, // 1MB
        'require_content_type' => 'application/json',
        'validate_json' => true,
        'sanitize_input' => true,
    ],

    'security_headers' => [
        'require_https' => env('WEBHOOK_REQUIRE_HTTPS', true),
        'content_type_validation' => true,
        'user_agent_required' => true,
    ],

    'audit_logging' => [
        'enabled' => true,
        'log_successful_requests' => false,
        'log_failed_requests' => true,
        'log_payload' => false, // Never log payloads for security
        'log_headers' => true,
        'redact_sensitive_headers' => true,
    ],

    'emergency_mode' => [
        'enabled' => env('WEBHOOK_EMERGENCY_MODE', false),
        'bypass_signature_validation' => false,
        'bypass_ip_validation' => true,
        'bypass_rate_limiting' => true,
        'log_emergency_access' => true,
    ],

    'development' => [
        'skip_hmac_validation' => env('WEBHOOK_DEV_SKIP_HMAC', false),
        'skip_paths' => [
            'webhooks/test',
            'webhooks/dev',
        ],
        'allow_http' => true,
        'log_debug_info' => true,
    ],

    'webhook_sources' => [
        'github' => [
            'secret_path' => 'webhooks/github_secret',
            'signature_header' => 'X-Hub-Signature-256',
            'algorithm' => 'sha256',
            'ip_ranges' => [
                '140.82.112.0/20',
                '185.199.108.0/22',
                '192.30.252.0/22',
                '143.55.64.0/20',
            ],
        ],
        'gitlab' => [
            'secret_path' => 'webhooks/gitlab_secret',
            'signature_header' => 'X-Gitlab-Token',
            'algorithm' => 'sha256',
            'ip_ranges' => [
                '35.231.145.151/32',
                '35.233.176.15/32',
            ],
        ],
        'stripe' => [
            'secret_path' => 'webhooks/stripe_secret',
            'signature_header' => 'Stripe-Signature',
            'algorithm' => 'sha256',
            'timestamp_tolerance' => 300,
        ],
        'temporal' => [
            'secret_path' => 'webhooks/temporal_secret',
            'signature_header' => 'X-Temporal-Signature',
            'algorithm' => 'sha256',
        ],
    ],

    'monitoring' => [
        'metrics_enabled' => true,
        'alert_on_failures' => true,
        'failure_threshold' => 10, // Alert after 10 failures
        'success_rate_threshold' => 0.95, // Alert if success rate drops below 95%
        'response_time_threshold' => 5000, // Alert if response time > 5s
    ],
];