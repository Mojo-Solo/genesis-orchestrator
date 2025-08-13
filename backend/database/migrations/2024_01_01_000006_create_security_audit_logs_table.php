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
        Schema::create('security_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('orchestration_run_id')->nullable();
            $table->foreign('orchestration_run_id')->references('id')->on('orchestration_runs')->onDelete('set null');
            
            // Event tracking
            $table->string('event_type'); // pii_detected, hmac_validation, rate_limit, injection_attempt
            $table->enum('severity', ['info', 'warning', 'critical']);
            $table->string('source_ip')->nullable();
            $table->string('user_agent')->nullable();
            
            // PII Detection
            $table->boolean('pii_detected')->default(false);
            $table->json('pii_types_found')->nullable(); // ['ssn', 'email', 'phone', 'credit_card']
            $table->boolean('pii_redacted')->default(false);
            $table->text('redacted_content')->nullable();
            
            // HMAC/Webhook
            $table->boolean('hmac_valid')->nullable();
            $table->string('webhook_source')->nullable();
            $table->string('signature_header')->nullable();
            $table->integer('timestamp_skew_seconds')->nullable();
            
            // Idempotency
            $table->string('idempotency_key')->nullable()->index();
            $table->boolean('duplicate_request')->default(false);
            
            // Rate Limiting
            $table->string('rate_limit_key')->nullable();
            $table->integer('requests_in_window')->nullable();
            $table->integer('rate_limit_max')->nullable();
            $table->boolean('rate_limit_exceeded')->default(false);
            
            // Injection Detection
            $table->boolean('injection_detected')->default(false);
            $table->string('injection_type')->nullable(); // sql, prompt, xss, etc.
            $table->text('suspicious_pattern')->nullable();
            
            // Response
            $table->string('action_taken'); // blocked, allowed, redacted, logged
            $table->integer('response_code')->nullable();
            
            $table->timestamps();
            $table->index(['event_type', 'created_at']);
            $table->index(['severity', 'created_at']);
            $table->index('pii_detected');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_audit_logs');
    }
};