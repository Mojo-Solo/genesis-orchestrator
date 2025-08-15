<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for budget management system.
     */
    public function up(): void
    {
        // Budget configurations table
        Schema::create('tenant_budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('budget_name');
            $table->enum('budget_type', ['monthly', 'quarterly', 'annual', 'custom']);
            $table->enum('scope', ['tenant', 'department', 'user', 'resource_type']);
            $table->string('scope_value')->nullable(); // department name, user_id, resource_type
            
            // Budget amounts
            $table->decimal('budget_amount', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->decimal('spent_amount', 12, 2)->default(0);
            $table->decimal('committed_amount', 12, 2)->default(0); // Forecasted spending
            $table->decimal('remaining_amount', 12, 2)->default(0);
            
            // Budget period
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('auto_renew')->default(false);
            
            // Threshold configurations
            $table->json('alert_thresholds')->default('[]'); // [50, 75, 90, 100]
            $table->json('alert_recipients')->default('[]'); // emails or user_ids
            $table->boolean('enforce_hard_limit')->default(false);
            
            // Status and metadata
            $table->enum('status', ['active', 'suspended', 'expired', 'draft'])->default('active');
            $table->json('metadata')->nullable();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign keys and indexes
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'status']);
            $table->index(['budget_type', 'start_date', 'end_date']);
            $table->index(['scope', 'scope_value']);
            $table->unique(['tenant_id', 'budget_name', 'start_date']);
        });
        
        // Budget alerts table
        Schema::create('budget_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('budget_id');
            $table->uuid('tenant_id');
            $table->string('alert_type'); // threshold, forecast, anomaly
            $table->decimal('threshold_percentage', 5, 2)->nullable();
            
            // Alert details
            $table->string('severity'); // low, medium, high, critical
            $table->string('title');
            $table->text('message');
            $table->json('alert_data'); // Additional alert context
            
            // Current state
            $table->decimal('current_spend', 12, 2);
            $table->decimal('budget_amount', 12, 2);
            $table->decimal('utilization_percentage', 5, 2);
            $table->date('period_start');
            $table->date('period_end');
            
            // Alert lifecycle
            $table->enum('status', ['active', 'acknowledged', 'resolved', 'suppressed'])->default('active');
            $table->timestamp('triggered_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('acknowledged_by')->nullable();
            $table->string('resolved_by')->nullable();
            $table->text('resolution_notes')->nullable();
            
            // Notification tracking
            $table->json('notification_channels')->default('[]'); // email, slack, webhook
            $table->json('notification_status')->default('{}'); // delivery status per channel
            $table->integer('notification_attempts')->default(0);
            $table->timestamp('last_notification_at')->nullable();
            
            $table->timestamps();
            
            // Foreign keys and indexes
            $table->foreign('budget_id')->references('id')->on('tenant_budgets')->onDelete('cascade');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'status', 'triggered_at']);
            $table->index(['budget_id', 'alert_type']);
            $table->index(['severity', 'status']);
        });
        
        // Budget forecasts table
        Schema::create('budget_forecasts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('budget_id');
            $table->uuid('tenant_id');
            
            // Forecast details
            $table->string('forecast_type'); // linear, exponential, seasonal, ml
            $table->date('forecast_date');
            $table->decimal('predicted_spend', 12, 2);
            $table->decimal('confidence_score', 5, 4); // 0-1
            $table->decimal('variance_estimate', 12, 2);
            
            // Confidence intervals
            $table->decimal('lower_bound', 12, 2);
            $table->decimal('upper_bound', 12, 2);
            $table->decimal('median_estimate', 12, 2);
            
            // Model metadata
            $table->json('model_parameters')->nullable();
            $table->json('historical_data_points')->nullable();
            $table->string('algorithm_version')->nullable();
            $table->decimal('model_accuracy', 5, 4)->nullable();
            
            // Validation
            $table->decimal('actual_spend', 12, 2)->nullable(); // Filled after the fact
            $table->decimal('forecast_error', 12, 2)->nullable();
            $table->boolean('is_validated')->default(false);
            
            $table->timestamps();
            
            // Foreign keys and indexes
            $table->foreign('budget_id')->references('id')->on('tenant_budgets')->onDelete('cascade');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['budget_id', 'forecast_date']);
            $table->index(['tenant_id', 'forecast_type']);
            $table->index(['confidence_score', 'forecast_date']);
        });
        
        // Cost allocation rules table
        Schema::create('cost_allocation_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('rule_name');
            $table->text('description')->nullable();
            
            // Allocation criteria
            $table->string('source_type'); // resource_type, user, department, project
            $table->json('source_filters'); // Conditions for matching resources
            $table->string('allocation_method'); // fixed, percentage, usage_based, cost_based
            
            // Allocation targets
            $table->json('allocation_targets'); // department, cost_center, project mappings
            $table->json('allocation_weights'); // Percentage or weights for each target
            
            // Rule configuration
            $table->integer('priority')->default(100); // Lower number = higher priority
            $table->boolean('is_active')->default(true);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            
            // Execution tracking
            $table->timestamp('last_executed_at')->nullable();
            $table->integer('execution_count')->default(0);
            $table->json('execution_stats')->nullable(); // Success/failure counts
            
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign keys and indexes
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'is_active', 'priority']);
            $table->index(['effective_from', 'effective_to']);
            $table->unique(['tenant_id', 'rule_name']);
        });
        
        // Cost anomalies detection table
        Schema::create('cost_anomalies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('anomaly_type'); // spike, trend_change, unusual_pattern
            $table->string('resource_type')->nullable();
            $table->string('scope')->nullable(); // department, user, resource
            $table->string('scope_value')->nullable();
            
            // Anomaly details
            $table->date('detected_date');
            $table->decimal('expected_value', 12, 2);
            $table->decimal('actual_value', 12, 2);
            $table->decimal('deviation_percentage', 5, 2);
            $table->decimal('severity_score', 5, 2); // 0-10
            
            // Detection metadata
            $table->string('detection_method'); // statistical, ml, rule_based
            $table->json('detection_parameters')->nullable();
            $table->decimal('confidence_score', 5, 4);
            $table->json('contributing_factors')->nullable();
            
            // Investigation tracking
            $table->enum('status', ['new', 'investigating', 'explained', 'false_positive', 'resolved'])->default('new');
            $table->text('investigation_notes')->nullable();
            $table->string('investigated_by')->nullable();
            $table->timestamp('investigated_at')->nullable();
            
            // Impact assessment
            $table->decimal('cost_impact', 12, 2)->nullable();
            $table->text('business_impact')->nullable();
            $table->json('recommended_actions')->nullable();
            
            $table->timestamps();
            
            // Foreign keys and indexes
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'status', 'detected_date']);
            $table->index(['anomaly_type', 'severity_score']);
            $table->index(['resource_type', 'detected_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cost_anomalies');
        Schema::dropIfExists('cost_allocation_rules');
        Schema::dropIfExists('budget_forecasts');
        Schema::dropIfExists('budget_alerts');
        Schema::dropIfExists('tenant_budgets');
    }
};