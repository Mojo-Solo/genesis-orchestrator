<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for multi-tenant architecture
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain')->nullable()->unique();
            
            // Tenant configuration
            $table->json('config')->nullable(); // Custom tenant settings
            $table->enum('status', ['active', 'suspended', 'pending', 'deleted'])->default('pending');
            $table->enum('tier', ['free', 'starter', 'professional', 'enterprise'])->default('free');
            
            // Resource quotas
            $table->integer('max_users')->default(5);
            $table->integer('max_orchestration_runs_per_month')->default(1000);
            $table->integer('max_tokens_per_month')->default(100000);
            $table->integer('max_storage_gb')->default(10);
            $table->integer('max_api_calls_per_minute')->default(100);
            
            // Usage tracking
            $table->integer('current_users')->default(0);
            $table->integer('current_orchestration_runs')->default(0);
            $table->integer('current_tokens_used')->default(0);
            $table->decimal('current_storage_gb', 10, 2)->default(0);
            $table->timestamp('usage_reset_at')->nullable();
            
            // Billing information
            $table->string('billing_email')->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_subscription_id')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();
            
            // Security
            $table->json('allowed_ip_ranges')->nullable();
            $table->boolean('enforce_mfa')->default(false);
            $table->boolean('sso_enabled')->default(false);
            $table->json('sso_config')->nullable();
            
            // Metadata
            $table->json('metadata')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for performance
            $table->index('status');
            $table->index('tier');
            $table->index('trial_ends_at');
            $table->index('subscription_ends_at');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};