<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    |
    | Controls the HTTP route that your GraphQL server responds to.
    | You may set `route` => false, to disable the default route
    | registration and take full control.
    |
    */
    'route' => [
        'uri' => '/graphql',
        'middleware' => [
            'web',
            \App\GraphQL\Middleware\AuthenticationMiddleware::class,
        ],
        'name' => 'graphql',
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema Configuration
    |--------------------------------------------------------------------------
    |
    | Points to the schema file that contains the type definitions.
    |
    */
    'schema' => [
        'register' => base_path('backend/app/GraphQL/Schema/schema.graphql'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema Cache
    |--------------------------------------------------------------------------
    |
    | A large schema can be costly to parse on each request.
    | Enable caching to optimize for production.
    |
    */
    'cache' => [
        'enable' => env('LIGHTHOUSE_CACHE_ENABLE', env('APP_ENV') === 'production'),
        'key' => env('LIGHTHOUSE_CACHE_KEY', 'lighthouse-schema'),
        'ttl' => env('LIGHTHOUSE_CACHE_TTL', 3600), // 1 hour
    ],

    /*
    |--------------------------------------------------------------------------
    | Namespaces
    |--------------------------------------------------------------------------
    |
    | These are the default namespaces where Lighthouse looks for classes.
    |
    */
    'namespaces' => [
        'models' => ['App\\Models'],
        'queries' => ['App\\GraphQL\\Queries'],
        'mutations' => ['App\\GraphQL\\Mutations'],
        'subscriptions' => ['App\\GraphQL\\Subscriptions'],
        'interfaces' => ['App\\GraphQL\\Interfaces'],
        'unions' => ['App\\GraphQL\\Unions'],
        'scalars' => ['App\\GraphQL\\Scalars'],
        'directives' => ['App\\GraphQL\\Directives'],
        'validators' => ['App\\GraphQL\\Validators'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | Control how Lighthouse handles security.
    |
    */
    'security' => [
        'max_query_complexity' => env('LIGHTHOUSE_QUERY_MAX_COMPLEXITY', 1000),
        'max_query_depth' => env('LIGHTHOUSE_QUERY_MAX_DEPTH', 15),
        'disable_introspection' => env('LIGHTHOUSE_DISABLE_INTROSPECTION', env('APP_ENV') === 'production'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | Default pagination limits and configuration.
    |
    */
    'pagination' => [
        'default_count' => 10,
        'max_count' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Configuration
    |--------------------------------------------------------------------------
    |
    | Control the debug information returned by GraphQL.
    |
    */
    'debug' => env('LIGHTHOUSE_DEBUG', env('APP_DEBUG', false)),

    /*
    |--------------------------------------------------------------------------
    | Error Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how errors are handled and reported.
    |
    */
    'error_handlers' => [
        \Nuwave\Lighthouse\Execution\ErrorHandlers\ReportingErrorHandler::class,
        \Nuwave\Lighthouse\Execution\ErrorHandlers\ExtensionErrorHandler::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Extensions
    |--------------------------------------------------------------------------
    |
    | List of extensions to enable.
    |
    */
    'extensions' => [
        \Nuwave\Lighthouse\Extensions\TracingExtension::class,
        \Nuwave\Lighthouse\Extensions\CacheExtension::class,
        \App\GraphQL\Extensions\SecurityExtension::class,
        \App\GraphQL\Extensions\MonitoringExtension::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscriptions
    |--------------------------------------------------------------------------
    |
    | Configuration for GraphQL subscriptions.
    |
    */
    'subscriptions' => [
        'storage' => env('LIGHTHOUSE_SUBSCRIPTION_STORAGE', 'redis'),
        'storage_ttl' => env('LIGHTHOUSE_SUBSCRIPTION_STORAGE_TTL', 3600),
        'broadcaster' => env('LIGHTHOUSE_BROADCASTER', 'pusher'),
        'version' => env('LIGHTHOUSE_SUBSCRIPTION_VERSION', 1),
        'webhooks' => [
            'enabled' => env('LIGHTHOUSE_SUBSCRIPTION_WEBHOOKS_ENABLED', false),
            'url' => env('LIGHTHOUSE_SUBSCRIPTION_WEBHOOK_URL'),
            'secret' => env('LIGHTHOUSE_SUBSCRIPTION_WEBHOOK_SECRET'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | GraphQL Playground
    |--------------------------------------------------------------------------
    |
    | Configuration for GraphQL Playground.
    |
    */
    'playground' => [
        'enabled' => env('LIGHTHOUSE_PLAYGROUND_ENABLED', env('APP_ENV') !== 'production'),
        'route' => 'graphql-playground',
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema Type Extensions
    |--------------------------------------------------------------------------
    |
    | Lighthouse provides some default type extensions that extend the GraphQL
    | type system. You can disable these if you want to define your own.
    |
    */
    'type_extensions' => [
        \Nuwave\Lighthouse\Schema\Extensions\ScoutExtension::class,
        \Nuwave\Lighthouse\Schema\Extensions\TracingExtension::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Global ID
    |--------------------------------------------------------------------------
    |
    | Configuration for Global ID encoding/decoding.
    |
    */
    'global_id_field' => env('LIGHTHOUSE_GLOBAL_ID_FIELD', 'id'),

    /*
    |--------------------------------------------------------------------------
    | Batched Queries
    |--------------------------------------------------------------------------
    |
    | Configuration for handling batched GraphQL queries.
    |
    */
    'batched_queries' => [
        'enabled' => env('LIGHTHOUSE_BATCHED_QUERIES_ENABLED', true),
        'max_batch_size' => env('LIGHTHOUSE_MAX_BATCH_SIZE', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Configuration
    |--------------------------------------------------------------------------
    |
    | Application-specific GraphQL configuration.
    |
    */
    'custom' => [
        // Tenant isolation settings
        'tenant_isolation' => [
            'enabled' => true,
            'strict_mode' => env('GRAPHQL_STRICT_TENANT_ISOLATION', true),
        ],

        // Performance monitoring
        'monitoring' => [
            'enabled' => true,
            'slow_query_threshold_ms' => env('GRAPHQL_SLOW_QUERY_THRESHOLD', 1000),
            'log_queries' => env('GRAPHQL_LOG_QUERIES', false),
            'metrics_collection' => env('GRAPHQL_METRICS_COLLECTION', true),
        ],

        // Rate limiting
        'rate_limiting' => [
            'enabled' => true,
            'default_limit' => env('GRAPHQL_DEFAULT_RATE_LIMIT', 1000),
            'window_seconds' => env('GRAPHQL_RATE_LIMIT_WINDOW', 3600),
            'per_user_limits' => [
                'free' => 100,
                'starter' => 500,
                'professional' => 2000,
                'enterprise' => 10000,
            ],
        ],

        // Security settings
        'security' => [
            'require_authentication' => env('GRAPHQL_REQUIRE_AUTH', true),
            'allow_anonymous_introspection' => env('GRAPHQL_ALLOW_ANONYMOUS_INTROSPECTION', false),
            'validate_csrf' => env('GRAPHQL_VALIDATE_CSRF', false),
            'content_security_policy' => env('GRAPHQL_CSP_ENABLED', true),
        ],

        // Caching configuration
        'caching' => [
            'query_cache_enabled' => env('GRAPHQL_QUERY_CACHE_ENABLED', true),
            'query_cache_ttl' => env('GRAPHQL_QUERY_CACHE_TTL', 300),
            'result_cache_enabled' => env('GRAPHQL_RESULT_CACHE_ENABLED', false),
            'result_cache_ttl' => env('GRAPHQL_RESULT_CACHE_TTL', 60),
        ],

        // Real-time features
        'realtime' => [
            'websocket_enabled' => env('GRAPHQL_WEBSOCKET_ENABLED', true),
            'websocket_port' => env('GRAPHQL_WEBSOCKET_PORT', 8080),
            'subscription_auth_required' => env('GRAPHQL_SUBSCRIPTION_AUTH_REQUIRED', true),
            'subscription_rate_limit' => env('GRAPHQL_SUBSCRIPTION_RATE_LIMIT', 50),
        ],

        // File upload settings
        'uploads' => [
            'max_file_size' => env('GRAPHQL_MAX_FILE_SIZE', 10485760), // 10MB
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'mp4', 'mp3', 'wav'],
            'storage_disk' => env('GRAPHQL_UPLOAD_DISK', 'private'),
            'virus_scan_enabled' => env('GRAPHQL_VIRUS_SCAN_ENABLED', false),
        ],

        // AI integration settings
        'ai_integration' => [
            'fireflies_enabled' => env('GRAPHQL_FIREFLIES_ENABLED', true),
            'pinecone_enabled' => env('GRAPHQL_PINECONE_ENABLED', true),
            'openai_enabled' => env('GRAPHQL_OPENAI_ENABLED', true),
            'insight_generation_enabled' => env('GRAPHQL_INSIGHT_GENERATION_ENABLED', true),
        ],

        // Audit logging
        'audit' => [
            'enabled' => env('GRAPHQL_AUDIT_ENABLED', true),
            'log_queries' => env('GRAPHQL_AUDIT_LOG_QUERIES', false),
            'log_mutations' => env('GRAPHQL_AUDIT_LOG_MUTATIONS', true),
            'log_subscriptions' => env('GRAPHQL_AUDIT_LOG_SUBSCRIPTIONS', true),
            'retention_days' => env('GRAPHQL_AUDIT_RETENTION_DAYS', 90),
        ],

        // Error handling
        'error_handling' => [
            'expose_stacktrace' => env('GRAPHQL_EXPOSE_STACKTRACE', env('APP_DEBUG', false)),
            'log_errors' => env('GRAPHQL_LOG_ERRORS', true),
            'sanitize_errors' => env('GRAPHQL_SANITIZE_ERRORS', env('APP_ENV') === 'production'),
            'error_reporting_service' => env('GRAPHQL_ERROR_REPORTING_SERVICE', null),
        ],

        // Development tools
        'development' => [
            'enable_query_logging' => env('GRAPHQL_ENABLE_QUERY_LOGGING', env('APP_DEBUG', false)),
            'enable_tracing' => env('GRAPHQL_ENABLE_TRACING', env('APP_DEBUG', false)),
            'playground_auth_header' => env('GRAPHQL_PLAYGROUND_AUTH_HEADER', ''),
        ],

        // Compliance and privacy
        'compliance' => [
            'gdpr_enabled' => env('GRAPHQL_GDPR_ENABLED', false),
            'data_retention_policy' => env('GRAPHQL_DATA_RETENTION_POLICY', '7 years'),
            'anonymization_enabled' => env('GRAPHQL_ANONYMIZATION_ENABLED', false),
            'consent_tracking' => env('GRAPHQL_CONSENT_TRACKING', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema Directives
    |--------------------------------------------------------------------------
    |
    | Register custom directives here.
    |
    */
    'directives' => [
        'App\\GraphQL\\Directives',
    ],

    /*
    |--------------------------------------------------------------------------
    | Type Middleware
    |--------------------------------------------------------------------------
    |
    | Register middleware that should run for specific types.
    |
    */
    'type_middleware' => [
        \App\GraphQL\Middleware\TenantIsolationMiddleware::class,
        \App\GraphQL\Middleware\PerformanceMiddleware::class,
        \App\GraphQL\Middleware\AuditMiddleware::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Field Middleware
    |--------------------------------------------------------------------------
    |
    | Register middleware that should run for specific fields.
    |
    */
    'field_middleware' => [
        'auth' => \App\GraphQL\Middleware\AuthFieldMiddleware::class,
        'can' => \App\GraphQL\Middleware\CanFieldMiddleware::class,
        'throttle' => \App\GraphQL\Middleware\ThrottleFieldMiddleware::class,
        'cache' => \App\GraphQL\Middleware\CacheFieldMiddleware::class,
        'tenant' => \App\GraphQL\Middleware\TenantFieldMiddleware::class,
    ],
];