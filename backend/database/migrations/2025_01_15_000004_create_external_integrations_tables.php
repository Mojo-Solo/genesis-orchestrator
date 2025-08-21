<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Webhook endpoints table
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('tenant_id');
            $table->string('url');
            $table->json('events');
            $table->string('secret');
            $table->boolean('active')->default(true);
            $table->json('retry_config')->nullable();
            $table->json('headers')->nullable();
            $table->integer('timeout')->default(30);
            $table->boolean('verify_ssl')->default(true);
            $table->string('disabled_reason')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'active']);
            $table->index('events');
        });

        // Webhook deliveries log
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('webhook_id', 32);
            $table->string('delivery_id', 32);
            $table->integer('attempt')->default(1);
            $table->integer('status_code')->nullable();
            $table->integer('duration_ms');
            $table->boolean('success');
            $table->integer('response_size')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            
            $table->foreign('webhook_id')->references('id')->on('webhook_endpoints')->onDelete('cascade');
            $table->index(['webhook_id', 'created_at']);
            $table->index(['delivery_id']);
            $table->index(['success', 'created_at']);
        });

        // Webhook dispatch log
        Schema::create('webhook_dispatch_log', function (Blueprint $table) {
            $table->id();
            $table->string('webhook_id', 32);
            $table->string('tenant_id');
            $table->string('event_type');
            $table->string('delivery_id', 32);
            $table->string('status');
            $table->timestamps();
            
            $table->foreign('webhook_id')->references('id')->on('webhook_endpoints')->onDelete('cascade');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'event_type']);
            $table->index(['webhook_id', 'created_at']);
        });

        // Webhook dead letter queue
        Schema::create('webhook_dead_letter_queue', function (Blueprint $table) {
            $table->id();
            $table->string('webhook_id', 32);
            $table->string('tenant_id');
            $table->string('delivery_id', 32);
            $table->string('url');
            $table->json('payload');
            $table->text('final_error');
            $table->timestamps();
            
            $table->foreign('webhook_id')->references('id')->on('webhook_endpoints')->onDelete('cascade');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['webhook_id']);
            $table->index(['tenant_id']);
        });

        // Tenant connector configurations
        Schema::create('tenant_connector_configurations', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('tenant_id');
            $table->string('connector_name');
            $table->text('configuration'); // Encrypted
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->unique(['tenant_id', 'connector_name']);
            $table->index('connector_name');
        });

        // API call metrics
        Schema::create('api_call_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('connector');
            $table->string('method');
            $table->string('endpoint');
            $table->integer('duration_ms');
            $table->boolean('success');
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'connector', 'created_at']);
            $table->index(['success', 'created_at']);
        });

        // Plugins table
        Schema::create('plugins', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('name')->unique();
            $table->string('display_name');
            $table->text('description');
            $table->string('version');
            $table->string('type');
            $table->string('author');
            $table->json('manifest');
            $table->string('path');
            $table->string('status')->default('active');
            $table->json('configuration_schema')->nullable();
            $table->json('capabilities')->nullable();
            $table->boolean('protected')->default(false);
            $table->string('installed_by');
            $table->timestamps();
            
            $table->foreign('installed_by')->references('id')->on('tenants')->onDelete('restrict');
            $table->index(['type', 'status']);
            $table->index('status');
        });

        // Tenant plugins (activation status)
        Schema::create('tenant_plugins', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('plugin_id', 32);
            $table->string('status')->default('inactive');
            $table->json('configuration')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('plugin_id')->references('id')->on('plugins')->onDelete('cascade');
            $table->unique(['tenant_id', 'plugin_id']);
            $table->index(['tenant_id', 'status']);
        });

        // Plugin execution log
        Schema::create('plugin_execution_log', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('plugin_name');
            $table->string('method');
            $table->integer('execution_time_ms');
            $table->boolean('success');
            $table->text('error')->nullable();
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'plugin_name', 'created_at']);
            $table->index(['success', 'created_at']);
        });

        // External system synchronization jobs
        Schema::create('sync_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('sync_id', 32)->unique();
            $table->string('source_system');
            $table->string('target_system');
            $table->string('sync_type');
            $table->string('status');
            $table->json('configuration');
            $table->json('source_filters')->nullable();
            $table->json('field_mapping')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('next_sync_at')->nullable();
            $table->json('sync_stats')->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'status']);
            $table->index(['source_system', 'target_system']);
            $table->index(['next_sync_at', 'active']);
        });

        // Sync execution log
        Schema::create('sync_executions', function (Blueprint $table) {
            $table->id();
            $table->string('sync_id', 32);
            $table->string('execution_id', 32)->unique();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('status');
            $table->integer('records_processed')->default(0);
            $table->integer('records_created')->default(0);
            $table->integer('records_updated')->default(0);
            $table->integer('records_deleted')->default(0);
            $table->integer('records_failed')->default(0);
            $table->json('performance_metrics')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamps();
            
            $table->foreign('sync_id')->references('sync_id')->on('sync_jobs')->onDelete('cascade');
            $table->index(['sync_id', 'created_at']);
            $table->index(['status', 'started_at']);
        });

        // Integration health checks
        Schema::create('integration_health_checks', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('integration_type'); // sso, api_connector, webhook, plugin, sync
            $table->string('integration_name');
            $table->string('status'); // healthy, degraded, unhealthy
            $table->integer('response_time_ms')->nullable();
            $table->json('health_data')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'integration_type', 'checked_at']);
            $table->index(['integration_type', 'integration_name', 'status']);
        });

        // Rate limiting tracking
        Schema::create('rate_limit_tracking', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('resource_type'); // api_marketplace, webhook_delivery, plugin_execution
            $table->string('resource_identifier');
            $table->integer('request_count');
            $table->timestamp('window_start');
            $table->timestamp('window_end');
            $table->boolean('limit_exceeded')->default(false);
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'resource_type', 'window_start']);
            $table->index(['resource_identifier', 'window_start']);
        });

        // Integration audit log
        Schema::create('integration_audit_log', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('integration_type');
            $table->string('integration_name');
            $table->string('action');
            $table->string('user_id')->nullable();
            $table->json('action_data')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->boolean('success');
            $table->text('error_message')->nullable();
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'integration_type', 'created_at']);
            $table->index(['action', 'success', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integration_audit_log');
        Schema::dropIfExists('rate_limit_tracking');
        Schema::dropIfExists('integration_health_checks');
        Schema::dropIfExists('sync_executions');
        Schema::dropIfExists('sync_jobs');
        Schema::dropIfExists('plugin_execution_log');
        Schema::dropIfExists('tenant_plugins');
        Schema::dropIfExists('plugins');
        Schema::dropIfExists('api_call_metrics');
        Schema::dropIfExists('tenant_connector_configurations');
        Schema::dropIfExists('webhook_dead_letter_queue');
        Schema::dropIfExists('webhook_dispatch_log');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};