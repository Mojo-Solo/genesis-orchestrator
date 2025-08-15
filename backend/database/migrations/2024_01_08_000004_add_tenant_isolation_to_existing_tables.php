<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add tenant_id columns to existing tables for complete data isolation.
     */
    public function up(): void
    {
        // Add tenant_id to orchestration_runs
        Schema::table('orchestration_runs', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable()->after('id');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });

        // Add tenant_id to agent_executions
        Schema::table('agent_executions', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable()->after('id');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });

        // Add tenant_id to memory_items
        Schema::table('memory_items', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable()->after('id');
            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'created_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });

        // Add tenant_id to router_metrics
        Schema::table('router_metrics', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable()->after('id');
            $table->index(['tenant_id', 'timestamp']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });

        // Add tenant_id to stability_tracking
        Schema::table('stability_tracking', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable()->after('id');
            $table->index(['tenant_id', 'timestamp']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });

        // Add tenant_id to security_audit_logs
        Schema::table('security_audit_logs', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable()->after('id');
            $table->index(['tenant_id', 'event_type']);
            $table->index(['tenant_id', 'created_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });

        // Add tenant_id to vault_audit_logs
        Schema::table('vault_audit_logs', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable()->after('id');
            $table->index(['tenant_id', 'action']);
            $table->index(['tenant_id', 'created_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove tenant_id columns and related indexes/foreign keys
        $tables = [
            'orchestration_runs',
            'agent_executions', 
            'memory_items',
            'router_metrics',
            'stability_tracking',
            'security_audit_logs',
            'vault_audit_logs'
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropForeign(['tenant_id']);
                    $table->dropIndex(['tenant_id', 'status']); // Try to drop if exists
                    $table->dropIndex(['tenant_id', 'created_at']); // Try to drop if exists
                    $table->dropColumn('tenant_id');
                });
            }
        }
    }
};