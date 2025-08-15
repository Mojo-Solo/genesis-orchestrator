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
        Schema::create('vault_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('orchestration_run_id')->nullable();
            $table->foreign('orchestration_run_id')->references('id')->on('orchestration_runs')->onDelete('set null');
            
            // Request identification
            $table->string('request_id')->nullable()->index();
            $table->timestamp('timestamp')->index();
            $table->string('client_token_hash')->nullable()->index();
            $table->string('client_id')->nullable()->index();
            $table->string('source_ip')->nullable()->index();
            $table->string('user_agent')->nullable();
            
            // Operation details
            $table->string('operation'); // read, write, delete, list, etc.
            $table->string('path')->index(); // Secret path
            $table->string('mount_point')->nullable();
            $table->enum('operation_type', ['request', 'response'])->index();
            
            // Request/Response data
            $table->string('http_method')->nullable();
            $table->integer('http_status_code')->nullable();
            $table->json('request_headers')->nullable();
            $table->json('request_parameters')->nullable();
            $table->json('response_headers')->nullable();
            $table->integer('response_size_bytes')->nullable();
            
            // Authentication and authorization
            $table->string('auth_method')->nullable(); // token, approle, jwt, etc.
            $table->json('auth_metadata')->nullable();
            $table->json('policies')->nullable();
            $table->json('token_policies')->nullable();
            $table->json('identity_policies')->nullable();
            $table->boolean('token_renewable')->nullable();
            $table->integer('token_ttl')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            
            // Secret-specific data
            $table->string('secret_version')->nullable();
            $table->json('secret_metadata')->nullable();
            $table->boolean('secret_deleted')->default(false);
            $table->boolean('secret_destroyed')->default(false);
            $table->json('secret_versions')->nullable();
            
            // Audit flags
            $table->boolean('hmac_accessor')->default(false);
            $table->boolean('request_logged')->default(true);
            $table->boolean('response_logged')->default(false);
            $table->boolean('error_occurred')->default(false);
            $table->string('error_type')->nullable();
            $table->text('error_message')->nullable();
            
            // Security events
            $table->boolean('security_violation')->default(false);
            $table->string('violation_type')->nullable(); // unauthorized_access, policy_violation, etc.
            $table->json('violation_details')->nullable();
            
            // Performance metrics
            $table->integer('duration_ms')->nullable();
            $table->integer('request_size_bytes')->nullable();
            $table->float('cpu_time_ms')->nullable();
            $table->integer('memory_usage_bytes')->nullable();
            
            // Compliance and retention
            $table->enum('data_classification', ['public', 'internal', 'confidential', 'restricted'])->default('internal');
            $table->boolean('retention_applied')->default(false);
            $table->timestamp('retention_expires_at')->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            
            // Correlation and tracing
            $table->string('correlation_id')->nullable()->index();
            $table->string('trace_id')->nullable()->index();
            $table->string('span_id')->nullable();
            $table->string('parent_span_id')->nullable();
            
            // Geolocation (optional)
            $table->string('country_code', 2)->nullable();
            $table->string('region')->nullable();
            $table->string('city')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            
            $table->timestamps();
            
            // Indexes for common queries
            $table->index(['path', 'timestamp']);
            $table->index(['operation', 'timestamp']);
            $table->index(['client_id', 'timestamp']);
            $table->index(['security_violation', 'timestamp']);
            $table->index(['error_occurred', 'timestamp']);
            $table->index(['data_classification', 'timestamp']);
            $table->index(['retention_expires_at']);
            $table->index(['archived', 'archived_at']);
            
            // Composite indexes for complex queries
            $table->index(['path', 'operation', 'timestamp']);
            $table->index(['client_id', 'operation', 'timestamp']);
            $table->index(['security_violation', 'violation_type', 'timestamp']);
            
            // Full-text search index for error messages (MySQL 5.7+)
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->fullText(['path', 'error_message']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vault_audit_logs');
    }
};