<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for tenant resource usage tracking.
     */
    public function up(): void
    {
        Schema::create('tenant_resource_usage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->date('usage_date')->index();
            $table->string('resource_type')->index(); // orchestration_runs, api_calls, storage, etc.
            
            // Usage metrics
            $table->unsignedBigInteger('total_usage')->default(0);
            $table->unsignedBigInteger('peak_usage')->default(0);
            $table->unsignedBigInteger('average_usage')->default(0);
            $table->unsignedInteger('unique_operations')->default(0);
            
            // Performance metrics
            $table->decimal('average_response_time_ms', 10, 2)->nullable();
            $table->decimal('p95_response_time_ms', 10, 2)->nullable();
            $table->decimal('error_rate_percent', 5, 2)->default(0);
            $table->unsignedInteger('total_errors')->default(0);
            
            // Cost tracking
            $table->decimal('cost_per_unit', 10, 6)->default(0);
            $table->decimal('total_cost', 10, 2)->default(0);
            $table->string('cost_currency', 3)->default('USD');
            
            // Resource-specific data
            $table->json('detailed_metrics')->nullable();
            $table->json('hourly_breakdown')->nullable();
            
            // Billing and invoicing
            $table->boolean('billed')->default(false);
            $table->uuid('invoice_id')->nullable();
            $table->timestamp('billed_at')->nullable();
            
            $table->timestamps();
            
            // Foreign key constraints
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            // Unique constraints - one record per tenant/date/resource_type
            $table->unique(['tenant_id', 'usage_date', 'resource_type']);
            
            // Indexes for performance
            $table->index(['tenant_id', 'usage_date']);
            $table->index(['resource_type', 'usage_date']);
            $table->index(['billed', 'tenant_id']);
            $table->index(['total_cost', 'tenant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_resource_usage');
    }
};