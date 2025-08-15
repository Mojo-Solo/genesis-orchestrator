<?php

return [
    /*
    |--------------------------------------------------------------------------
    | External Integrations Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for GENESIS Orchestrator external integrations including
    | SSO providers, API marketplace, webhook delivery, and plugin architecture.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | SSO Integration Configuration
    |--------------------------------------------------------------------------
    |
    | Single Sign-On integration settings for SAML and OIDC providers.
    |
    */
    'sso' => [
        'enabled' => env('INTEGRATION_SSO_ENABLED', true),
        'default_provider' => env('INTEGRATION_SSO_DEFAULT_PROVIDER', 'saml'),
        
        'saml' => [
            'enabled' => env('INTEGRATION_SAML_ENABLED', true),
            'idp_metadata_url' => env('SAML_IDP_METADATA_URL'),
            'idp_entity_id' => env('SAML_IDP_ENTITY_ID'),
            'idp_sso_url' => env('SAML_IDP_SSO_URL'),
            'idp_slo_url' => env('SAML_IDP_SLO_URL'),
            'x509_cert' => env('SAML_X509_CERT'),
            'private_key' => env('SAML_PRIVATE_KEY'),
            'sp_entity_id' => env('SAML_SP_ENTITY_ID', 'genesis-orchestrator'),
            'sp_acs_url' => env('SAML_SP_ACS_URL', '/sso/saml/acs'),
            'sp_sls_url' => env('SAML_SP_SLS_URL', '/sso/saml/sls'),
            'name_id_format' => env('SAML_NAME_ID_FORMAT', 'urn:oasis:names:tc:SAML:2.0:nameid-format:emailAddress'),
            'attribute_mapping' => [
                'email' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
                'name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
                'first_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname',
                'last_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname',
                'department' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/department',
                'role' => 'http://schemas.microsoft.com/ws/2008/06/identity/claims/role',
            ],
            'auto_provision' => env('SAML_AUTO_PROVISION', true),
            'default_role' => env('SAML_DEFAULT_ROLE', 'user'),
        ],

        'oidc' => [
            'enabled' => env('INTEGRATION_OIDC_ENABLED', true),
            'providers' => [
                'azure' => [
                    'client_id' => env('OIDC_AZURE_CLIENT_ID'),
                    'client_secret' => env('OIDC_AZURE_CLIENT_SECRET'),
                    'tenant_id' => env('OIDC_AZURE_TENANT_ID'),
                    'discovery_url' => env('OIDC_AZURE_DISCOVERY_URL', 'https://login.microsoftonline.com/{tenant_id}/v2.0/.well-known/openid_configuration'),
                    'scope' => ['openid', 'profile', 'email'],
                    'claims_mapping' => [
                        'email' => 'email',
                        'name' => 'name',
                        'first_name' => 'given_name',
                        'last_name' => 'family_name',
                        'role' => 'roles',
                    ],
                ],
                'google' => [
                    'client_id' => env('OIDC_GOOGLE_CLIENT_ID'),
                    'client_secret' => env('OIDC_GOOGLE_CLIENT_SECRET'),
                    'discovery_url' => 'https://accounts.google.com/.well-known/openid_configuration',
                    'scope' => ['openid', 'profile', 'email'],
                    'claims_mapping' => [
                        'email' => 'email',
                        'name' => 'name',
                        'first_name' => 'given_name',
                        'last_name' => 'family_name',
                    ],
                ],
                'okta' => [
                    'client_id' => env('OIDC_OKTA_CLIENT_ID'),
                    'client_secret' => env('OIDC_OKTA_CLIENT_SECRET'),
                    'domain' => env('OIDC_OKTA_DOMAIN'),
                    'discovery_url' => env('OIDC_OKTA_DISCOVERY_URL', 'https://{domain}/.well-known/openid_configuration'),
                    'scope' => ['openid', 'profile', 'email', 'groups'],
                    'claims_mapping' => [
                        'email' => 'email',
                        'name' => 'name',
                        'first_name' => 'given_name',
                        'last_name' => 'family_name',
                        'groups' => 'groups',
                    ],
                ],
            ],
            'auto_provision' => env('OIDC_AUTO_PROVISION', true),
            'default_role' => env('OIDC_DEFAULT_ROLE', 'user'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | API Marketplace Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for third-party API integrations and marketplace.
    |
    */
    'api_marketplace' => [
        'enabled' => env('INTEGRATION_API_MARKETPLACE_ENABLED', true),
        'auto_discovery' => env('API_MARKETPLACE_AUTO_DISCOVERY', true),
        'rate_limit_global' => env('API_MARKETPLACE_RATE_LIMIT', 10000), // per hour
        'timeout_default' => env('API_MARKETPLACE_TIMEOUT', 30), // seconds
        'retry_attempts' => env('API_MARKETPLACE_RETRY_ATTEMPTS', 3),
        'circuit_breaker_enabled' => env('API_MARKETPLACE_CIRCUIT_BREAKER', true),
        
        'connectors' => [
            'slack' => [
                'enabled' => env('CONNECTOR_SLACK_ENABLED', true),
                'api_base_url' => 'https://slack.com/api',
                'oauth_base_url' => 'https://slack.com/oauth/v2',
                'scopes' => ['chat:write', 'channels:read', 'users:read'],
                'rate_limit' => [
                    'requests_per_minute' => 20,
                    'burst_size' => 5,
                ],
                'webhook_events' => ['message', 'app_mention', 'channel_created'],
            ],
            'microsoft_teams' => [
                'enabled' => env('CONNECTOR_TEAMS_ENABLED', true),
                'api_base_url' => 'https://graph.microsoft.com/v1.0',
                'oauth_base_url' => 'https://login.microsoftonline.com',
                'scopes' => ['https://graph.microsoft.com/ChannelMessage.Send', 'https://graph.microsoft.com/Chat.ReadWrite'],
                'rate_limit' => [
                    'requests_per_minute' => 30,
                    'burst_size' => 10,
                ],
            ],
            'jira' => [
                'enabled' => env('CONNECTOR_JIRA_ENABLED', true),
                'api_base_url' => env('JIRA_BASE_URL', 'https://your-domain.atlassian.net'),
                'api_version' => '3',
                'rate_limit' => [
                    'requests_per_minute' => 300,
                    'burst_size' => 50,
                ],
                'webhook_events' => ['issue_created', 'issue_updated', 'issue_deleted'],
            ],
            'github' => [
                'enabled' => env('CONNECTOR_GITHUB_ENABLED', true),
                'api_base_url' => 'https://api.github.com',
                'app_id' => env('GITHUB_APP_ID'),
                'private_key' => env('GITHUB_PRIVATE_KEY'),
                'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
                'rate_limit' => [
                    'requests_per_hour' => 5000,
                    'burst_size' => 100,
                ],
                'webhook_events' => ['push', 'pull_request', 'issues', 'deployment'],
            ],
            'gitlab' => [
                'enabled' => env('CONNECTOR_GITLAB_ENABLED', true),
                'api_base_url' => env('GITLAB_BASE_URL', 'https://gitlab.com/api/v4'),
                'rate_limit' => [
                    'requests_per_minute' => 300,
                    'burst_size' => 60,
                ],
                'webhook_events' => ['push', 'merge_request', 'issue', 'pipeline'],
            ],
            'salesforce' => [
                'enabled' => env('CONNECTOR_SALESFORCE_ENABLED', true),
                'api_base_url' => env('SALESFORCE_INSTANCE_URL'),
                'api_version' => 'v59.0',
                'rate_limit' => [
                    'requests_per_day' => 100000,
                    'burst_size' => 200,
                ],
                'webhook_events' => ['opportunity_created', 'contact_updated', 'account_modified'],
            ],
            'zendesk' => [
                'enabled' => env('CONNECTOR_ZENDESK_ENABLED', true),
                'api_base_url' => env('ZENDESK_SUBDOMAIN', 'https://your-subdomain.zendesk.com/api/v2'),
                'rate_limit' => [
                    'requests_per_minute' => 200,
                    'burst_size' => 40,
                ],
                'webhook_events' => ['ticket_created', 'ticket_updated', 'user_created'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Delivery Engine Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for outbound webhook delivery with reliability guarantees.
    |
    */
    'webhook_delivery' => [
        'enabled' => env('INTEGRATION_WEBHOOK_DELIVERY_ENABLED', true),
        'max_retries' => env('WEBHOOK_DELIVERY_MAX_RETRIES', 5),
        'retry_delays' => [30, 120, 600, 3600, 14400], // seconds: 30s, 2m, 10m, 1h, 4h
        'timeout' => env('WEBHOOK_DELIVERY_TIMEOUT', 30), // seconds
        'verify_ssl' => env('WEBHOOK_DELIVERY_VERIFY_SSL', true),
        'max_payload_size' => env('WEBHOOK_DELIVERY_MAX_PAYLOAD_SIZE', 1048576), // 1MB
        'dead_letter_queue' => env('WEBHOOK_DELIVERY_DLQ_ENABLED', true),
        'batch_size' => env('WEBHOOK_DELIVERY_BATCH_SIZE', 100),
        'rate_limit_per_endpoint' => env('WEBHOOK_DELIVERY_RATE_LIMIT', 100), // per minute
        
        'events' => [
            'orchestration_started' => [
                'enabled' => true,
                'payload_template' => 'orchestration_started',
                'retry_policy' => 'exponential_backoff',
            ],
            'orchestration_completed' => [
                'enabled' => true,
                'payload_template' => 'orchestration_completed',
                'retry_policy' => 'exponential_backoff',
            ],
            'agent_execution_started' => [
                'enabled' => true,
                'payload_template' => 'agent_execution_started',
                'retry_policy' => 'linear_backoff',
            ],
            'agent_execution_completed' => [
                'enabled' => true,
                'payload_template' => 'agent_execution_completed',
                'retry_policy' => 'linear_backoff',
            ],
            'quota_warning' => [
                'enabled' => true,
                'payload_template' => 'quota_warning',
                'retry_policy' => 'exponential_backoff',
                'priority' => 'high',
            ],
            'security_incident' => [
                'enabled' => true,
                'payload_template' => 'security_incident',
                'retry_policy' => 'immediate',
                'priority' => 'critical',
            ],
            'tenant_created' => [
                'enabled' => true,
                'payload_template' => 'tenant_created',
                'retry_policy' => 'exponential_backoff',
            ],
            'billing_event' => [
                'enabled' => true,
                'payload_template' => 'billing_event',
                'retry_policy' => 'exponential_backoff',
                'priority' => 'high',
            ],
        ],

        'security' => [
            'sign_payloads' => env('WEBHOOK_DELIVERY_SIGN_PAYLOADS', true),
            'signature_algorithm' => env('WEBHOOK_DELIVERY_SIGNATURE_ALGORITHM', 'sha256'),
            'signature_header' => env('WEBHOOK_DELIVERY_SIGNATURE_HEADER', 'X-Genesis-Signature'),
            'timestamp_header' => env('WEBHOOK_DELIVERY_TIMESTAMP_HEADER', 'X-Genesis-Timestamp'),
            'user_agent' => env('WEBHOOK_DELIVERY_USER_AGENT', 'GENESIS-Orchestrator-Webhook/1.0'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Plugin Architecture Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for extensible plugin system for custom integrations.
    |
    */
    'plugins' => [
        'enabled' => env('INTEGRATION_PLUGINS_ENABLED', true),
        'auto_discovery' => env('PLUGIN_AUTO_DISCOVERY', true),
        'plugin_directory' => env('PLUGIN_DIRECTORY', storage_path('plugins')),
        'cache_enabled' => env('PLUGIN_CACHE_ENABLED', true),
        'cache_ttl' => env('PLUGIN_CACHE_TTL', 3600), // 1 hour
        'security_scan' => env('PLUGIN_SECURITY_SCAN', true),
        'sandbox_enabled' => env('PLUGIN_SANDBOX_ENABLED', true),
        'max_execution_time' => env('PLUGIN_MAX_EXECUTION_TIME', 30), // seconds
        'max_memory_limit' => env('PLUGIN_MAX_MEMORY_LIMIT', '128M'),
        
        'types' => [
            'webhook_processor' => [
                'enabled' => true,
                'max_instances' => 10,
                'lifecycle_hooks' => ['before_process', 'after_process', 'on_error'],
            ],
            'data_transformer' => [
                'enabled' => true,
                'max_instances' => 20,
                'lifecycle_hooks' => ['transform', 'validate', 'on_error'],
            ],
            'notification_channel' => [
                'enabled' => true,
                'max_instances' => 15,
                'lifecycle_hooks' => ['send', 'on_success', 'on_failure'],
            ],
            'auth_provider' => [
                'enabled' => true,
                'max_instances' => 5,
                'lifecycle_hooks' => ['authenticate', 'authorize', 'on_login', 'on_logout'],
            ],
            'storage_adapter' => [
                'enabled' => true,
                'max_instances' => 10,
                'lifecycle_hooks' => ['store', 'retrieve', 'delete', 'on_error'],
            ],
            'monitoring_collector' => [
                'enabled' => true,
                'max_instances' => 8,
                'lifecycle_hooks' => ['collect', 'aggregate', 'alert'],
            ],
        ],

        'registry' => [
            'enabled' => env('PLUGIN_REGISTRY_ENABLED', true),
            'url' => env('PLUGIN_REGISTRY_URL', 'https://plugins.genesis-orchestrator.com'),
            'auto_update' => env('PLUGIN_AUTO_UPDATE', false),
            'verify_signatures' => env('PLUGIN_VERIFY_SIGNATURES', true),
            'public_key' => env('PLUGIN_REGISTRY_PUBLIC_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | External System Synchronization
    |--------------------------------------------------------------------------
    |
    | Configuration for real-time data synchronization with external systems.
    |
    */
    'synchronization' => [
        'enabled' => env('INTEGRATION_SYNC_ENABLED', true),
        'default_sync_interval' => env('SYNC_DEFAULT_INTERVAL', 300), // 5 minutes
        'conflict_resolution' => env('SYNC_CONFLICT_RESOLUTION', 'last_write_wins'), // last_write_wins, manual, custom
        'change_detection' => env('SYNC_CHANGE_DETECTION', 'timestamp'), // timestamp, hash, incremental
        'batch_size' => env('SYNC_BATCH_SIZE', 1000),
        'parallel_workers' => env('SYNC_PARALLEL_WORKERS', 5),
        'retry_failed_sync' => env('SYNC_RETRY_FAILED', true),
        'max_sync_retries' => env('SYNC_MAX_RETRIES', 3),
        
        'providers' => [
            'database' => [
                'enabled' => true,
                'connections' => [
                    'postgresql' => env('SYNC_POSTGRES_ENABLED', false),
                    'mysql' => env('SYNC_MYSQL_ENABLED', true),
                    'sqlserver' => env('SYNC_SQLSERVER_ENABLED', false),
                    'oracle' => env('SYNC_ORACLE_ENABLED', false),
                ],
                'sync_mode' => 'incremental', // full, incremental, differential
                'change_tracking' => true,
            ],
            'api' => [
                'enabled' => true,
                'authentication' => [
                    'oauth2' => true,
                    'api_key' => true,
                    'basic_auth' => true,
                    'jwt' => true,
                ],
                'pagination_support' => true,
                'rate_limit_awareness' => true,
            ],
            'file_system' => [
                'enabled' => true,
                'protocols' => ['sftp', 'ftp', 's3', 'azure_blob', 'gcs'],
                'file_formats' => ['csv', 'json', 'xml', 'xlsx', 'parquet'],
                'watch_directories' => true,
            ],
            'message_queue' => [
                'enabled' => true,
                'providers' => ['kafka', 'rabbitmq', 'azure_service_bus', 'aws_sqs'],
                'guaranteed_delivery' => true,
                'order_preservation' => true,
            ],
        ],

        'monitoring' => [
            'track_sync_performance' => true,
            'alert_on_sync_failures' => true,
            'sync_lag_threshold' => 600, // 10 minutes
            'error_rate_threshold' => 0.05, // 5%
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Coordination
    |--------------------------------------------------------------------------
    |
    | Cross-system rate limiting and throttling coordination.
    |
    */
    'rate_limiting' => [
        'cross_system_enabled' => env('INTEGRATION_CROSS_SYSTEM_RATE_LIMITING', true),
        'distributed_cache' => env('RATE_LIMIT_DISTRIBUTED_CACHE', 'redis'),
        'coordination_protocol' => env('RATE_LIMIT_COORDINATION_PROTOCOL', 'redis_pub_sub'),
        'global_rate_limit' => env('INTEGRATION_GLOBAL_RATE_LIMIT', 100000), // per hour
        'tenant_isolation' => env('RATE_LIMIT_TENANT_ISOLATION', true),
        'priority_queuing' => env('RATE_LIMIT_PRIORITY_QUEUING', true),
        
        'algorithms' => [
            'token_bucket' => [
                'enabled' => true,
                'default_capacity' => 1000,
                'default_refill_rate' => 100, // per minute
            ],
            'sliding_window' => [
                'enabled' => true,
                'default_window_size' => 3600, // 1 hour
                'default_request_limit' => 10000,
            ],
            'fixed_window' => [
                'enabled' => true,
                'default_window_size' => 60, // 1 minute
                'default_request_limit' => 100,
            ],
        ],

        'external_system_limits' => [
            'slack_api' => [
                'requests_per_minute' => 20,
                'burst_capacity' => 5,
            ],
            'github_api' => [
                'requests_per_hour' => 5000,
                'burst_capacity' => 100,
            ],
            'microsoft_graph' => [
                'requests_per_minute' => 30,
                'burst_capacity' => 10,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Integration Monitoring and Alerting
    |--------------------------------------------------------------------------
    |
    | Comprehensive monitoring for all integration components.
    |
    */
    'monitoring' => [
        'enabled' => env('INTEGRATION_MONITORING_ENABLED', true),
        'metrics_collection_interval' => env('INTEGRATION_METRICS_INTERVAL', 60), // seconds
        'health_check_interval' => env('INTEGRATION_HEALTH_CHECK_INTERVAL', 300), // 5 minutes
        'circuit_breaker_enabled' => env('INTEGRATION_CIRCUIT_BREAKER_ENABLED', true),
        
        'health_checks' => [
            'sso_providers' => [
                'enabled' => true,
                'timeout' => 10,
                'critical' => true,
            ],
            'api_connectors' => [
                'enabled' => true,
                'timeout' => 30,
                'critical' => false,
            ],
            'webhook_delivery' => [
                'enabled' => true,
                'timeout' => 5,
                'critical' => true,
            ],
            'sync_services' => [
                'enabled' => true,
                'timeout' => 60,
                'critical' => false,
            ],
        ],

        'alerts' => [
            'integration_failure' => [
                'enabled' => true,
                'threshold' => 5, // failures in 5 minutes
                'channels' => ['email', 'webhook', 'slack'],
                'priority' => 'high',
            ],
            'high_error_rate' => [
                'enabled' => true,
                'threshold' => 0.10, // 10% error rate
                'time_window' => 300, // 5 minutes
                'channels' => ['email', 'webhook'],
                'priority' => 'medium',
            ],
            'sync_lag' => [
                'enabled' => true,
                'threshold' => 900, // 15 minutes
                'channels' => ['email'],
                'priority' => 'low',
            ],
            'rate_limit_exceeded' => [
                'enabled' => true,
                'threshold' => 0.90, // 90% of rate limit
                'channels' => ['webhook'],
                'priority' => 'medium',
            ],
        ],

        'metrics' => [
            'request_volume' => true,
            'response_times' => true,
            'error_rates' => true,
            'throughput' => true,
            'queue_lengths' => true,
            'cache_hit_rates' => true,
            'sync_performance' => true,
            'webhook_delivery_success' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security and Compliance
    |--------------------------------------------------------------------------
    |
    | Security settings for external integrations.
    |
    */
    'security' => [
        'encryption_at_rest' => env('INTEGRATION_ENCRYPTION_AT_REST', true),
        'encryption_in_transit' => env('INTEGRATION_ENCRYPTION_IN_TRANSIT', true),
        'audit_all_requests' => env('INTEGRATION_AUDIT_ALL_REQUESTS', true),
        'pii_detection' => env('INTEGRATION_PII_DETECTION', true),
        'data_loss_prevention' => env('INTEGRATION_DLP_ENABLED', true),
        'vulnerability_scanning' => env('INTEGRATION_VULN_SCAN_ENABLED', true),
        
        'access_control' => [
            'require_mfa_for_admin' => env('INTEGRATION_REQUIRE_MFA_ADMIN', true),
            'ip_whitelist_enabled' => env('INTEGRATION_IP_WHITELIST_ENABLED', true),
            'session_timeout' => env('INTEGRATION_SESSION_TIMEOUT', 3600), // 1 hour
        ],

        'data_protection' => [
            'gdpr_compliance' => env('INTEGRATION_GDPR_COMPLIANCE', true),
            'data_retention_days' => env('INTEGRATION_DATA_RETENTION_DAYS', 365),
            'automatic_data_purging' => env('INTEGRATION_AUTO_DATA_PURGE', true),
            'consent_management' => env('INTEGRATION_CONSENT_MANAGEMENT', true),
        ],
    ],
];