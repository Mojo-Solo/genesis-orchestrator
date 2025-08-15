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
        // Data Classification and Sensitivity
        Schema::create('data_classifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            $table->string('data_type'); // 'user_data', 'system_data', 'business_data', 'metadata'
            $table->string('classification'); // 'public', 'internal', 'confidential', 'restricted'
            $table->string('sensitivity_level'); // 'low', 'medium', 'high', 'critical'
            $table->json('pii_categories')->nullable(); // ['name', 'email', 'phone', 'ssn', 'credit_card']
            $table->json('special_categories')->nullable(); // ['health', 'biometric', 'genetic', 'political']
            
            $table->string('table_name')->nullable();
            $table->string('column_name')->nullable();
            $table->string('data_source')->nullable(); // 'user_input', 'api_response', 'system_generated'
            
            $table->integer('retention_days')->nullable();
            $table->boolean('requires_encryption')->default(false);
            $table->boolean('requires_anonymization')->default(false);
            $table->boolean('cross_border_restricted')->default(false);
            
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['tenant_id', 'data_type']);
            $table->index(['classification', 'sensitivity_level']);
            $table->index(['table_name', 'column_name']);
        });

        // Consent Management
        Schema::create('consent_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            $table->uuid('data_subject_id'); // Can be tenant_user_id or external user ID
            $table->string('data_subject_type')->default('tenant_user'); // 'tenant_user', 'external_user'
            
            $table->string('consent_type'); // 'data_processing', 'marketing', 'analytics', 'cookies'
            $table->string('processing_purpose'); // 'service_provision', 'analytics', 'marketing', 'legal_compliance'
            $table->text('consent_description');
            
            $table->enum('consent_status', ['granted', 'withdrawn', 'expired', 'pending'])->default('pending');
            $table->timestamp('consent_given_at')->nullable();
            $table->timestamp('consent_withdrawn_at')->nullable();
            $table->timestamp('consent_expires_at')->nullable();
            
            $table->string('consent_method'); // 'explicit_opt_in', 'implied', 'legitimate_interest'
            $table->string('consent_source'); // 'web_form', 'api', 'phone', 'paper'
            $table->json('consent_evidence')->nullable(); // IP, user agent, form data
            
            $table->boolean('is_active')->default(true);
            $table->boolean('is_granular')->default(false); // Can be withdrawn partially
            $table->json('granular_permissions')->nullable(); // Specific permissions granted
            
            $table->uuid('superseded_by')->nullable(); // References another consent record
            $table->foreign('superseded_by')->references('id')->on('consent_records')->onDelete('set null');
            
            $table->timestamps();
            
            $table->index(['tenant_id', 'data_subject_id']);
            $table->index(['consent_type', 'consent_status']);
            $table->index(['consent_expires_at']);
        });

        // Data Subject Rights Requests
        Schema::create('data_subject_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            $table->uuid('data_subject_id');
            $table->string('data_subject_type')->default('tenant_user');
            $table->string('request_reference')->unique(); // Human-readable reference
            
            $table->enum('request_type', [
                'access', // Article 15 - Right of access
                'rectification', // Article 16 - Right to rectification
                'erasure', // Article 17 - Right to erasure
                'restrict_processing', // Article 18 - Right to restriction
                'data_portability', // Article 20 - Right to data portability
                'object_processing', // Article 21 - Right to object
                'withdraw_consent' // Withdraw consent
            ]);
            
            $table->enum('status', [
                'pending',
                'under_review',
                'in_progress', 
                'completed',
                'rejected',
                'partially_completed'
            ])->default('pending');
            
            $table->text('request_description');
            $table->json('requested_data_categories')->nullable(); // Specific data types requested
            $table->json('identity_verification')->nullable(); // How identity was verified
            
            $table->timestamp('received_at');
            $table->timestamp('due_date'); // 30 days from received_at
            $table->timestamp('completed_at')->nullable();
            
            $table->text('rejection_reason')->nullable();
            $table->json('actions_taken')->nullable(); // What was done to fulfill request
            $table->string('export_file_path')->nullable(); // For data portability requests
            
            $table->uuid('handled_by')->nullable(); // User who handled the request
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['tenant_id', 'data_subject_id']);
            $table->index(['request_type', 'status']);
            $table->index(['due_date']);
            $table->index(['received_at']);
        });

        // Data Retention Policies
        Schema::create('data_retention_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            $table->string('policy_name');
            $table->text('policy_description');
            $table->boolean('is_active')->default(true);
            
            $table->string('data_category'); // What type of data this policy applies to
            $table->string('legal_basis'); // 'consent', 'contract', 'legal_obligation', 'legitimate_interest'
            
            $table->integer('retention_period_days');
            $table->enum('retention_action', ['delete', 'anonymize', 'archive', 'notify_review']);
            
            $table->json('conditions')->nullable(); // Conditions for policy application
            $table->json('exceptions')->nullable(); // Exceptions to the policy
            
            $table->boolean('auto_execute')->default(false);
            $table->string('notification_emails')->nullable(); // Who to notify before action
            $table->integer('warning_days')->default(30); // Days before action to warn
            
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            
            $table->uuid('created_by');
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            
            $table->timestamps();
            
            $table->index(['tenant_id', 'is_active']);
            $table->index(['data_category', 'effective_from']);
        });

        // Data Retention Executions
        Schema::create('data_retention_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            $table->uuid('policy_id');
            $table->foreign('policy_id')->references('id')->on('data_retention_policies')->onDelete('cascade');
            
            $table->string('execution_type'); // 'scheduled', 'manual', 'triggered'
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'cancelled']);
            
            $table->timestamp('scheduled_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            
            $table->integer('records_identified')->default(0);
            $table->integer('records_processed')->default(0);
            $table->integer('records_deleted')->default(0);
            $table->integer('records_anonymized')->default(0);
            $table->integer('records_archived')->default(0);
            $table->integer('records_failed')->default(0);
            
            $table->json('affected_tables')->nullable();
            $table->text('execution_log')->nullable();
            $table->text('error_details')->nullable();
            
            $table->uuid('executed_by')->nullable();
            $table->timestamps();
            
            $table->index(['tenant_id', 'policy_id']);
            $table->index(['status', 'scheduled_at']);
        });

        // Privacy Settings
        Schema::create('privacy_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            $table->uuid('user_id')->nullable(); // If null, applies to all tenant users
            $table->foreign('user_id')->references('id')->on('tenant_users')->onDelete('cascade');
            
            $table->string('setting_key'); // 'data_processing', 'marketing_emails', 'analytics_tracking'
            $table->string('setting_value'); // 'enabled', 'disabled', 'limited'
            $table->text('setting_description')->nullable();
            
            $table->boolean('user_configurable')->default(true);
            $table->boolean('requires_consent')->default(false);
            $table->json('allowed_values')->nullable(); // Valid values for this setting
            
            $table->timestamp('last_updated_at');
            $table->uuid('updated_by')->nullable();
            $table->string('update_reason')->nullable(); // 'user_request', 'policy_change', 'consent_withdrawal'
            
            $table->timestamps();
            
            $table->unique(['tenant_id', 'user_id', 'setting_key']);
            $table->index(['tenant_id', 'setting_key']);
        });

        // Privacy Impact Assessments
        Schema::create('privacy_impact_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            $table->string('assessment_name');
            $table->text('description');
            $table->string('project_or_system');
            
            $table->enum('status', ['draft', 'under_review', 'approved', 'rejected', 'requires_update']);
            $table->enum('risk_level', ['low', 'medium', 'high', 'very_high']);
            
            $table->json('data_types_processed'); // Types of personal data
            $table->json('processing_purposes'); // Why data is processed
            $table->json('data_sources'); // Where data comes from
            $table->json('data_recipients'); // Who receives the data
            $table->json('transfers_outside_eea')->nullable(); // International transfers
            
            $table->text('necessity_justification'); // Why processing is necessary
            $table->text('proportionality_assessment'); // Is processing proportionate
            $table->json('risks_identified'); // Privacy risks identified
            $table->json('mitigation_measures'); // How risks are mitigated
            
            $table->uuid('conducted_by');
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('conducted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('next_review_due')->nullable();
            
            $table->timestamps();
            
            $table->index(['tenant_id', 'status']);
            $table->index(['risk_level', 'next_review_due']);
        });

        // Compliance Audit Trail
        Schema::create('compliance_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            $table->string('event_type'); // 'consent_granted', 'data_deleted', 'export_generated'
            $table->string('compliance_area'); // 'gdpr', 'ccpa', 'hipaa', 'sox'
            $table->enum('severity', ['info', 'warning', 'error', 'critical']);
            
            $table->uuid('data_subject_id')->nullable();
            $table->string('data_subject_type')->nullable();
            
            $table->text('event_description');
            $table->json('event_data')->nullable(); // Structured data about the event
            $table->json('legal_basis')->nullable(); // Legal justification for the action
            
            $table->string('source_system'); // Where the event originated
            $table->string('source_ip')->nullable();
            $table->string('user_agent')->nullable();
            
            $table->uuid('performed_by')->nullable();
            $table->timestamp('performed_at');
            
            $table->boolean('automated_action')->default(false);
            $table->string('workflow_id')->nullable(); // If part of automated workflow
            
            $table->timestamps();
            
            $table->index(['tenant_id', 'event_type']);
            $table->index(['compliance_area', 'severity']);
            $table->index(['data_subject_id', 'performed_at']);
            $table->index(['performed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('compliance_audit_logs');
        Schema::dropIfExists('privacy_impact_assessments');
        Schema::dropIfExists('privacy_settings');
        Schema::dropIfExists('data_retention_executions');
        Schema::dropIfExists('data_retention_policies');
        Schema::dropIfExists('data_subject_requests');
        Schema::dropIfExists('consent_records');
        Schema::dropIfExists('data_classifications');
    }
};