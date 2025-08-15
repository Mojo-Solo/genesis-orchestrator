<?php

return [
    /*
    |--------------------------------------------------------------------------
    | HashiCorp Vault Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration array defines how to connect to HashiCorp Vault
    | for production-grade secrets management. Supports both development
    | mode with filesystem storage and production Vault clusters.
    |
    */

    'driver' => env('VAULT_DRIVER', 'hashicorp'),

    'drivers' => [
        'hashicorp' => [
            'url' => env('VAULT_URL', 'http://127.0.0.1:8200'),
            'token' => env('VAULT_TOKEN'),
            'version' => env('VAULT_VERSION', 'v1'),
            'timeout' => env('VAULT_TIMEOUT', 30),
            'verify' => env('VAULT_VERIFY_SSL', true),
            'namespace' => env('VAULT_NAMESPACE', 'genesis'),
            'auth_method' => env('VAULT_AUTH_METHOD', 'token'),
            'role_id' => env('VAULT_ROLE_ID'),
            'secret_id' => env('VAULT_SECRET_ID'),
            'jwt' => env('VAULT_JWT'),
            'role' => env('VAULT_ROLE', 'genesis-orchestrator'),
        ],

        'aws_secrets' => [
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'version' => '2017-10-17',
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                'token' => env('AWS_SESSION_TOKEN'),
            ],
            'prefix' => env('AWS_SECRETS_PREFIX', 'genesis/'),
        ],

        'filesystem' => [
            'path' => storage_path('secrets'),
            'encryption_key' => env('FILESYSTEM_SECRETS_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Secret Rotation Configuration
    |--------------------------------------------------------------------------
    |
    | Configure automatic secret rotation policies. These settings control
    | how often secrets are rotated and what actions are taken when 
    | rotation occurs.
    |
    */

    'rotation' => [
        'enabled' => env('VAULT_ROTATION_ENABLED', true),
        'default_ttl' => env('VAULT_DEFAULT_TTL', 2592000), // 30 days
        'warning_threshold' => env('VAULT_WARNING_THRESHOLD', 604800), // 7 days
        'auto_rotate' => env('VAULT_AUTO_ROTATE', true),
        'rotation_schedule' => env('VAULT_ROTATION_SCHEDULE', '0 2 * * 0'), // Sunday 2 AM
        
        'policies' => [
            'api_keys' => [
                'ttl' => 604800, // 7 days
                'renewable' => false,
                'notify_on_rotation' => true,
            ],
            'database_credentials' => [
                'ttl' => 86400, // 24 hours
                'renewable' => true,
                'notify_on_rotation' => false,
            ],
            'webhook_secrets' => [
                'ttl' => 2592000, // 30 days
                'renewable' => true,
                'notify_on_rotation' => true,
            ],
            'encryption_keys' => [
                'ttl' => 7776000, // 90 days
                'renewable' => false,
                'notify_on_rotation' => true,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Role-Based Access Control (RBAC)
    |--------------------------------------------------------------------------
    |
    | Define access policies for different roles and services. Each role
    | can have different permissions for reading, writing, and managing
    | specific secret paths.
    |
    */

    'rbac' => [
        'enabled' => env('VAULT_RBAC_ENABLED', true),
        
        'roles' => [
            'genesis-admin' => [
                'policies' => ['genesis-admin-policy'],
                'ttl' => 3600, // 1 hour
                'max_ttl' => 28800, // 8 hours
                'renewable' => true,
                'bound_cidrs' => ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
            ],
            
            'genesis-service' => [
                'policies' => ['genesis-service-policy'],
                'ttl' => 1800, // 30 minutes
                'max_ttl' => 7200, // 2 hours
                'renewable' => true,
                'bound_cidrs' => [],
            ],
            
            'genesis-readonly' => [
                'policies' => ['genesis-readonly-policy'],
                'ttl' => 900, // 15 minutes
                'max_ttl' => 3600, // 1 hour
                'renewable' => true,
                'bound_cidrs' => [],
            ],
        ],

        'policies' => [
            'genesis-admin-policy' => [
                'genesis/*' => ['create', 'read', 'update', 'delete', 'list'],
                'sys/policies/acl/*' => ['create', 'read', 'update', 'delete', 'list'],
                'auth/*' => ['create', 'read', 'update', 'delete', 'list'],
            ],
            
            'genesis-service-policy' => [
                'genesis/api_keys/*' => ['read'],
                'genesis/database/*' => ['read'],
                'genesis/webhooks/*' => ['read'],
                'genesis/encryption/*' => ['read'],
            ],
            
            'genesis-readonly-policy' => [
                'genesis/public/*' => ['read', 'list'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Configuration
    |--------------------------------------------------------------------------
    |
    | Configure audit logging for all secret operations. This ensures
    | complete traceability of who accessed what secrets when.
    |
    */

    'audit' => [
        'enabled' => env('VAULT_AUDIT_ENABLED', true),
        'log_requests' => env('VAULT_AUDIT_REQUESTS', true),
        'log_responses' => env('VAULT_AUDIT_RESPONSES', false),
        'hmac_accessor' => env('VAULT_AUDIT_HMAC_ACCESSOR', true),
        'log_raw' => env('VAULT_AUDIT_LOG_RAW', false),
        'prefix' => env('VAULT_AUDIT_PREFIX', 'vault_audit'),
        
        'file_audit' => [
            'enabled' => true,
            'file_path' => storage_path('logs/vault-audit.log'),
            'format' => 'json',
        ],
        
        'database_audit' => [
            'enabled' => true,
            'table' => 'vault_audit_logs',
            'connection' => env('VAULT_AUDIT_DB_CONNECTION', 'default'),
        ],
        
        'syslog_audit' => [
            'enabled' => false,
            'facility' => 'local0',
            'tag' => 'vault-audit',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Encryption Configuration
    |--------------------------------------------------------------------------
    |
    | Configure encryption at rest for secrets stored in the filesystem
    | driver or additional encryption layers for enhanced security.
    |
    */

    'encryption' => [
        'enabled' => env('VAULT_ENCRYPTION_ENABLED', true),
        'cipher' => env('VAULT_ENCRYPTION_CIPHER', 'AES-256-GCM'),
        'key_derivation' => env('VAULT_KEY_DERIVATION', 'PBKDF2'),
        'iterations' => env('VAULT_KDF_ITERATIONS', 100000),
        'salt_length' => 32,
        'iv_length' => 12,
        
        'transit_engine' => [
            'enabled' => env('VAULT_TRANSIT_ENABLED', true),
            'mount_path' => env('VAULT_TRANSIT_MOUNT', 'transit'),
            'key_name' => env('VAULT_TRANSIT_KEY', 'genesis-master-key'),
            'key_type' => 'aes256-gcm96',
            'derived' => true,
            'exportable' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Check Configuration
    |--------------------------------------------------------------------------
    |
    | Configure health checks to monitor Vault connectivity and status.
    | These checks ensure the secrets management system is operational.
    |
    */

    'health' => [
        'enabled' => env('VAULT_HEALTH_CHECKS', true),
        'check_interval' => env('VAULT_HEALTH_INTERVAL', 60), // seconds
        'timeout' => env('VAULT_HEALTH_TIMEOUT', 5),
        'retry_attempts' => env('VAULT_HEALTH_RETRIES', 3),
        'retry_delay' => 1, // seconds
        
        'checks' => [
            'vault_connection' => true,
            'vault_seal_status' => true,
            'vault_policies' => true,
            'secret_accessibility' => true,
        ],
        
        'alerts' => [
            'enabled' => true,
            'webhook_url' => env('VAULT_ALERT_WEBHOOK'),
            'channels' => ['log', 'webhook', 'database'],
            'severity_levels' => ['critical', 'warning'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Development Configuration
    |--------------------------------------------------------------------------
    |
    | Special configuration for development environments. In development,
    | you may want to use less secure but more convenient settings.
    |
    */

    'development' => [
        'enabled' => env('APP_ENV') !== 'production',
        'allow_insecure_vault' => env('VAULT_DEV_MODE', false),
        'mock_rotation' => env('VAULT_MOCK_ROTATION', false),
        'cache_secrets' => env('VAULT_CACHE_SECRETS', true),
        'cache_ttl' => 300, // 5 minutes
        'log_secret_access' => true,
    ],
];