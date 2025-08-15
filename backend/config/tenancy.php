<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tenant Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration options for the multi-tenant
    | GENESIS Orchestrator system. These settings control tenant isolation,
    | quotas, billing, and security features.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Tenant Resolution
    |--------------------------------------------------------------------------
    |
    | Configure how tenants are identified and resolved from incoming requests.
    | Multiple methods can be enabled simultaneously for maximum flexibility.
    |
    */
    'resolution' => [
        // Enable subdomain-based tenant resolution (tenant.api.genesis.com)
        'subdomain' => [
            'enabled' => env('TENANT_SUBDOMAIN_ENABLED', true),
            'cache_ttl' => env('TENANT_SUBDOMAIN_CACHE_TTL', 300), // 5 minutes
        ],

        // Enable API key-based tenant resolution
        'api_key' => [
            'enabled' => env('TENANT_API_KEY_ENABLED', true),
            'header_name' => env('TENANT_API_KEY_HEADER', 'X-API-Key'),
            'cache_ttl' => env('TENANT_API_KEY_CACHE_TTL', 300),
        ],

        // Enable JWT token-based tenant resolution
        'jwt' => [
            'enabled' => env('TENANT_JWT_ENABLED', true),
            'secret' => env('TENANT_JWT_SECRET', env('APP_KEY')),
            'algorithm' => env('TENANT_JWT_ALGORITHM', 'HS256'),
            'cache_ttl' => env('TENANT_JWT_CACHE_TTL', 300),
        ],

        // Enable header-based tenant resolution
        'header' => [
            'enabled' => env('TENANT_HEADER_ENABLED', true),
            'tenant_header' => env('TENANT_HEADER_NAME', 'X-Tenant-ID'),
            'user_header' => env('TENANT_USER_HEADER_NAME', 'X-User-ID'),
            'cache_ttl' => env('TENANT_HEADER_CACHE_TTL', 300),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Tier Limits
    |--------------------------------------------------------------------------
    |
    | Default resource limits for each tenant tier. These can be overridden
    | on a per-tenant basis if needed.
    |
    */
    'tier_limits' => [
        'free' => [
            'max_users' => 5,
            'max_orchestration_runs_per_month' => 1000,
            'max_tokens_per_month' => 100000,
            'max_storage_gb' => 10,
            'max_api_calls_per_minute' => 100,
            'features' => [
                'api_access' => true,
                'analytics' => true,
                'custom_agents' => false,
                'priority_support' => false,
                'sso' => false,
                'webhooks' => false,
                'export_data' => false,
                'audit_logs' => false,
            ],
        ],

        'starter' => [
            'max_users' => 25,
            'max_orchestration_runs_per_month' => 10000,
            'max_tokens_per_month' => 1000000,
            'max_storage_gb' => 100,
            'max_api_calls_per_minute' => 500,
            'features' => [
                'api_access' => true,
                'analytics' => true,
                'custom_agents' => true,
                'priority_support' => false,
                'sso' => false,
                'webhooks' => true,
                'export_data' => true,
                'audit_logs' => false,
            ],
        ],

        'professional' => [
            'max_users' => 100,
            'max_orchestration_runs_per_month' => 50000,
            'max_tokens_per_month' => 5000000,
            'max_storage_gb' => 500,
            'max_api_calls_per_minute' => 2000,
            'features' => [
                'api_access' => true,
                'analytics' => true,
                'custom_agents' => true,
                'priority_support' => true,
                'sso' => false,
                'webhooks' => true,
                'export_data' => true,
                'audit_logs' => true,
            ],
        ],

        'enterprise' => [
            'max_users' => -1, // Unlimited
            'max_orchestration_runs_per_month' => -1,
            'max_tokens_per_month' => -1,
            'max_storage_gb' => -1,
            'max_api_calls_per_minute' => 10000,
            'features' => [
                'api_access' => true,
                'analytics' => true,
                'custom_agents' => true,
                'priority_support' => true,
                'sso' => true,
                'webhooks' => true,
                'export_data' => true,
                'audit_logs' => true,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource Pricing
    |--------------------------------------------------------------------------
    |
    | Pricing configuration for different resource types. Used for billing
    | calculations and cost attribution.
    |
    */
    'pricing' => [
        'currency' => env('TENANT_BILLING_CURRENCY', 'USD'),
        'resources' => [
            'orchestration_runs' => [
                'cost_per_unit' => env('COST_PER_ORCHESTRATION_RUN', 0.10),
                'unit' => 'run',
                'description' => 'Cost per orchestration run execution',
            ],
            'api_calls' => [
                'cost_per_unit' => env('COST_PER_1K_API_CALLS', 1.00) / 1000,
                'unit' => 'call',
                'description' => 'Cost per API call',
            ],
            'tokens' => [
                'cost_per_unit' => env('COST_PER_1K_TOKENS', 0.002) / 1000,
                'unit' => 'token',
                'description' => 'Cost per token processed',
            ],
            'storage' => [
                'cost_per_unit' => env('COST_PER_GB_STORAGE', 0.50),
                'unit' => 'GB',
                'description' => 'Cost per GB storage per month',
            ],
            'bandwidth' => [
                'cost_per_unit' => env('COST_PER_GB_BANDWIDTH', 0.12),
                'unit' => 'GB',
                'description' => 'Cost per GB bandwidth transfer',
            ],
            'agent_executions' => [
                'cost_per_unit' => env('COST_PER_AGENT_EXECUTION', 0.05),
                'unit' => 'execution',
                'description' => 'Cost per agent execution',
            ],
            'memory_items' => [
                'cost_per_unit' => env('COST_PER_MEMORY_ITEM', 0.001),
                'unit' => 'item',
                'description' => 'Cost per memory item stored',
            ],
            'router_calls' => [
                'cost_per_unit' => env('COST_PER_ROUTER_CALL', 0.0001),
                'unit' => 'call',
                'description' => 'Cost per router call',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Quota Management
    |--------------------------------------------------------------------------
    |
    | Configuration for quota tracking, warnings, and enforcement.
    |
    */
    'quotas' => [
        // Percentage at which to send warning notifications
        'warning_threshold' => env('QUOTA_WARNING_THRESHOLD', 90),

        // How often to check quotas (in minutes)
        'check_interval' => env('QUOTA_CHECK_INTERVAL', 60),

        // Grace period for quota violations (in minutes)
        'grace_period' => env('QUOTA_GRACE_PERIOD', 60),

        // Whether to automatically suspend tenants that exceed quotas
        'auto_suspend' => env('QUOTA_AUTO_SUSPEND', false),

        // Usage reset schedule (cron expression)
        'reset_schedule' => env('QUOTA_RESET_SCHEDULE', '0 0 1 * *'), // 1st of month

        // Cache TTL for quota checks (in seconds)
        'cache_ttl' => env('QUOTA_CACHE_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    |
    | Security configuration for tenant isolation and access control.
    |
    */
    'security' => [
        // IP address restrictions
        'ip_restrictions' => [
            'enabled' => env('TENANT_IP_RESTRICTIONS_ENABLED', true),
            'default_policy' => env('TENANT_IP_DEFAULT_POLICY', 'allow'), // allow|deny
        ],

        // Multi-factor authentication
        'mfa' => [
            'enabled' => env('TENANT_MFA_ENABLED', true),
            'totp_issuer' => env('TENANT_MFA_TOTP_ISSUER', 'GENESIS Orchestrator'),
            'backup_codes_count' => env('TENANT_MFA_BACKUP_CODES', 8),
        ],

        // Single Sign-On
        'sso' => [
            'enabled' => env('TENANT_SSO_ENABLED', true),
            'supported_providers' => [
                'saml' => env('TENANT_SSO_SAML_ENABLED', true),
                'oauth2' => env('TENANT_SSO_OAUTH2_ENABLED', true),
                'ldap' => env('TENANT_SSO_LDAP_ENABLED', true),
            ],
        ],

        // Session management
        'session' => [
            'timeout' => env('TENANT_SESSION_TIMEOUT', 3600), // 1 hour
            'max_concurrent' => env('TENANT_MAX_CONCURRENT_SESSIONS', 5),
            'idle_timeout' => env('TENANT_IDLE_TIMEOUT', 1800), // 30 minutes
        ],

        // API security
        'api' => [
            'rate_limit_enabled' => env('TENANT_API_RATE_LIMIT_ENABLED', true),
            'require_https' => env('TENANT_API_REQUIRE_HTTPS', true),
            'cors_enabled' => env('TENANT_API_CORS_ENABLED', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Configuration
    |--------------------------------------------------------------------------
    |
    | Database-related settings for tenant isolation.
    |
    */
    'database' => [
        // Whether to use separate databases per tenant
        'separate_databases' => env('TENANT_SEPARATE_DATABASES', false),

        // Database connection template for tenant databases
        'tenant_connection_template' => env('TENANT_DB_CONNECTION_TEMPLATE', 'mysql'),

        // Database prefix for tenant tables (if using shared database)
        'table_prefix' => env('TENANT_DB_TABLE_PREFIX', ''),

        // Whether to enable query scoping for tenant isolation
        'query_scoping' => env('TENANT_QUERY_SCOPING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching Configuration
    |--------------------------------------------------------------------------
    |
    | Cache settings for tenant-related data.
    |
    */
    'cache' => [
        // Cache store to use for tenant data
        'store' => env('TENANT_CACHE_STORE', 'redis'),

        // Default TTL for tenant cache entries (in seconds)
        'default_ttl' => env('TENANT_CACHE_TTL', 3600),

        // Cache key prefix
        'prefix' => env('TENANT_CACHE_PREFIX', 'tenant'),

        // Whether to enable cache tags for better invalidation
        'tags_enabled' => env('TENANT_CACHE_TAGS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for tenant-related notifications.
    |
    */
    'notifications' => [
        // Default notification channels
        'channels' => [
            'email' => env('TENANT_NOTIFICATIONS_EMAIL_ENABLED', true),
            'webhook' => env('TENANT_NOTIFICATIONS_WEBHOOK_ENABLED', true),
            'slack' => env('TENANT_NOTIFICATIONS_SLACK_ENABLED', false),
        ],

        // Notification types and their default settings
        'types' => [
            'quota_warning' => [
                'enabled' => true,
                'channels' => ['email'],
                'throttle' => 3600, // 1 hour between warnings
            ],
            'quota_exceeded' => [
                'enabled' => true,
                'channels' => ['email', 'webhook'],
                'throttle' => 300, // 5 minutes
            ],
            'billing_failed' => [
                'enabled' => true,
                'channels' => ['email'],
                'throttle' => 3600,
            ],
            'security_alert' => [
                'enabled' => true,
                'channels' => ['email', 'webhook'],
                'throttle' => 0, // No throttling for security alerts
            ],
            'trial_expiring' => [
                'enabled' => true,
                'channels' => ['email'],
                'throttle' => 86400, // 1 day
            ],
            'subscription_expiring' => [
                'enabled' => true,
                'channels' => ['email'],
                'throttle' => 86400,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Logging
    |--------------------------------------------------------------------------
    |
    | Configuration for tenant audit logging and compliance.
    |
    */
    'audit' => [
        // Whether to enable audit logging
        'enabled' => env('TENANT_AUDIT_ENABLED', true),

        // Log all tenant requests
        'log_requests' => env('TENANT_AUDIT_LOG_REQUESTS', true),

        // Log data access and modifications
        'log_data_access' => env('TENANT_AUDIT_LOG_DATA_ACCESS', true),

        // Log authentication events
        'log_auth_events' => env('TENANT_AUDIT_LOG_AUTH_EVENTS', true),

        // Log configuration changes
        'log_config_changes' => env('TENANT_AUDIT_LOG_CONFIG_CHANGES', true),

        // Retention period for audit logs (in days)
        'retention_days' => env('TENANT_AUDIT_RETENTION_DAYS', 365),

        // Whether to encrypt audit logs
        'encrypt_logs' => env('TENANT_AUDIT_ENCRYPT_LOGS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup and Recovery
    |--------------------------------------------------------------------------
    |
    | Configuration for tenant data backup and recovery.
    |
    */
    'backup' => [
        // Whether to enable automatic backups
        'enabled' => env('TENANT_BACKUP_ENABLED', true),

        // Backup schedule (cron expression)
        'schedule' => env('TENANT_BACKUP_SCHEDULE', '0 2 * * *'), // Daily at 2 AM

        // Backup retention period (in days)
        'retention_days' => env('TENANT_BACKUP_RETENTION_DAYS', 30),

        // Backup storage configuration
        'storage' => [
            'disk' => env('TENANT_BACKUP_DISK', 's3'),
            'path' => env('TENANT_BACKUP_PATH', 'tenant-backups'),
            'encrypt' => env('TENANT_BACKUP_ENCRYPT', true),
        ],

        // Point-in-time recovery settings
        'pitr' => [
            'enabled' => env('TENANT_PITR_ENABLED', true),
            'retention_hours' => env('TENANT_PITR_RETENTION_HOURS', 168), // 7 days
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Monitoring
    |--------------------------------------------------------------------------
    |
    | Configuration for tenant performance monitoring and optimization.
    |
    */
    'monitoring' => [
        // Whether to enable performance monitoring
        'enabled' => env('TENANT_MONITORING_ENABLED', true),

        // Metrics collection interval (in seconds)
        'collection_interval' => env('TENANT_MONITORING_INTERVAL', 60),

        // Performance thresholds
        'thresholds' => [
            'response_time_ms' => env('TENANT_THRESHOLD_RESPONSE_TIME', 2000),
            'error_rate_percent' => env('TENANT_THRESHOLD_ERROR_RATE', 5),
            'cpu_usage_percent' => env('TENANT_THRESHOLD_CPU_USAGE', 80),
            'memory_usage_percent' => env('TENANT_THRESHOLD_MEMORY_USAGE', 80),
        ],

        // Alerting configuration
        'alerting' => [
            'enabled' => env('TENANT_ALERTING_ENABLED', true),
            'channels' => ['email', 'webhook'],
            'cooldown_minutes' => env('TENANT_ALERTING_COOLDOWN', 15),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Tenant Configuration
    |--------------------------------------------------------------------------
    |
    | Default configuration applied to new tenants.
    |
    */
    'defaults' => [
        'status' => 'pending', // pending|active|suspended
        'tier' => 'free',
        'trial_days' => env('TENANT_DEFAULT_TRIAL_DAYS', 14),
        'enforce_mfa' => false,
        'sso_enabled' => false,
        
        'config' => [
            'features' => [
                'api_access' => true,
                'analytics' => true,
            ],
            'notifications' => [
                'quota_warnings' => true,
                'billing_alerts' => true,
                'security_alerts' => true,
            ],
            'security' => [
                'session_timeout' => 3600,
                'password_policy' => 'standard',
                'mfa_required' => false,
            ],
        ],
    ],
];