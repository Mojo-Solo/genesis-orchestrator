<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Project Management System - Multi-Tenant Database Schema
 * 
 * Comprehensive database schema for AI-enhanced project management with
 * multi-tenant isolation, Fireflies integration, and intelligent insights.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ==============================================
        // CORE MULTI-TENANT INFRASTRUCTURE
        // ==============================================
        
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain')->nullable();
            $table->enum('tier', ['free', 'starter', 'professional', 'enterprise'])->default('free');
            $table->enum('status', ['active', 'suspended', 'cancelled'])->default('active');
            
            // Subscription & Billing
            $table->json('subscription_data')->nullable();
            $table->decimal('monthly_cost', 10, 2)->default(0);
            $table->timestamp('subscription_started_at')->nullable();
            $table->timestamp('subscription_expires_at')->nullable();
            
            // API & Integration Settings
            $table->string('fireflies_api_key')->nullable();
            $table->boolean('fireflies_enabled')->default(false);
            $table->string('openai_api_key')->nullable();
            $table->string('pinecone_api_key')->nullable();
            $table->json('integration_settings')->nullable();
            
            // Resource Quotas by Tier
            $table->integer('max_users')->default(5);
            $table->integer('max_meetings_per_month')->default(50);
            $table->integer('max_transcript_hours_per_month')->default(10);
            $table->integer('max_ai_requests_per_month')->default(1000);
            $table->integer('max_storage_gb')->default(1);
            
            // Usage Tracking
            $table->integer('current_users')->default(0);
            $table->integer('current_month_meetings')->default(0);
            $table->decimal('current_month_transcript_hours', 8, 2)->default(0);
            $table->integer('current_month_ai_requests')->default(0);
            $table->decimal('current_storage_gb', 8, 2)->default(0);
            
            // Security & Compliance
            $table->json('security_settings')->nullable();
            $table->boolean('gdpr_compliant')->default(true);
            $table->boolean('soc2_compliant')->default(false);
            $table->timestamp('last_security_audit')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['tier', 'status']);
            $table->index('subscription_expires_at');
        });

        Schema::create('tenant_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->enum('role', ['admin', 'manager', 'member', 'viewer'])->default('member');
            $table->enum('status', ['active', 'invited', 'suspended'])->default('invited');
            
            // Profile & Preferences
            $table->string('avatar_url')->nullable();
            $table->string('timezone', 50)->default('UTC');
            $table->string('language', 5)->default('en');
            $table->json('preferences')->nullable();
            $table->json('notification_settings')->nullable();
            
            // AI Personalization
            $table->json('ai_preferences')->nullable();
            $table->decimal('ai_interaction_score', 5, 2)->default(0);
            $table->json('learning_data')->nullable();
            
            // Access & Security
            $table->json('permissions')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->string('last_ip_address')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            $table->text('two_factor_secret')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'role', 'status']);
        });

        // ==============================================
        // MEETING & TRANSCRIPT MANAGEMENT
        // ==============================================
        
        Schema::create('meetings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('creator_id')->nullable();
            $table->string('fireflies_id')->nullable()->unique();
            
            // Meeting Details
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('type', ['scheduled', 'ad_hoc', 'recurring', 'imported'])->default('scheduled');
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'cancelled'])->default('scheduled');
            
            // Scheduling
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('duration_minutes')->nullable();
            $table->string('meeting_url')->nullable();
            $table->string('meeting_platform')->nullable(); // zoom, teams, meet, etc.
            
            // Participants
            $table->json('participants')->nullable(); // Array of participant data
            $table->integer('participant_count')->default(0);
            $table->json('external_participants')->nullable(); // Non-tenant participants
            
            // AI Processing
            $table->boolean('transcription_enabled')->default(true);
            $table->boolean('ai_analysis_enabled')->default(true);
            $table->enum('processing_status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->decimal('processing_progress', 5, 2)->default(0);
            
            // Metadata
            $table->json('metadata')->nullable();
            $table->json('tags')->nullable();
            $table->uuid('project_id')->nullable();
            $table->uuid('workspace_id')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('creator_id')->references('id')->on('tenant_users')->onDelete('set null');
            $table->index(['tenant_id', 'status', 'scheduled_at']);
            $table->index(['tenant_id', 'processing_status']);
        });

        Schema::create('meeting_transcripts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('meeting_id');
            $table->string('fireflies_transcript_id')->nullable();
            
            // Transcript Content
            $table->longText('content');
            $table->json('sentences')->nullable(); // Structured sentence data with timestamps
            $table->json('speakers')->nullable(); // Speaker identification and stats
            $table->json('summary')->nullable(); // AI-generated summary
            $table->json('ai_filters')->nullable(); // Fireflies AI filter results
            
            // Processing Metadata
            $table->enum('processing_status', ['pending_analysis', 'analyzing', 'completed', 'failed'])->default('pending_analysis');
            $table->decimal('confidence_score', 5, 3)->default(0);
            $table->string('language', 10)->default('en');
            $table->integer('word_count')->default(0);
            $table->integer('speaker_count')->default(0);
            
            // Quality Metrics
            $table->decimal('audio_quality_score', 5, 3)->nullable();
            $table->decimal('transcription_accuracy', 5, 3)->nullable();
            $table->json('quality_metrics')->nullable();
            
            // AI Analysis Results
            $table->json('topics_extracted')->nullable();
            $table->json('sentiment_analysis')->nullable();
            $table->json('key_phrases')->nullable();
            $table->decimal('engagement_score', 5, 2)->nullable();
            
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('meeting_id')->references('id')->on('meetings')->onDelete('cascade');
            $table->index(['tenant_id', 'processing_status']);
            $table->index('meeting_id');
        });

        // ==============================================
        // AI INSIGHTS & ANALYTICS
        // ==============================================
        
        Schema::create('meeting_insights', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('meeting_id');
            $table->uuid('transcript_id');
            
            // Insight Types
            $table->enum('insight_type', [
                'effectiveness', 'sentiment', 'decisions', 'productivity', 
                'predictions', 'engagement', 'topics', 'action_items'
            ]);
            
            // Insight Data
            $table->json('insights_data');
            $table->decimal('confidence_score', 5, 3);
            $table->text('summary')->nullable();
            $table->json('key_findings')->nullable();
            
            // Performance Metrics
            $table->decimal('relevance_score', 5, 3)->nullable();
            $table->integer('user_rating')->nullable(); // 1-5 rating from users
            $table->boolean('user_validated')->default(false);
            
            // Processing Info
            $table->string('ai_model_used', 100)->nullable();
            $table->decimal('processing_time_ms', 10, 2)->nullable();
            $table->timestamp('generated_at');
            
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('meeting_id')->references('id')->on('meetings')->onDelete('cascade');
            $table->foreign('transcript_id')->references('id')->on('meeting_transcripts')->onDelete('cascade');
            $table->index(['tenant_id', 'insight_type']);
            $table->index(['meeting_id', 'insight_type']);
        });

        Schema::create('action_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('meeting_id');
            $table->uuid('transcript_id')->nullable();
            $table->uuid('assigned_to')->nullable();
            $table->uuid('created_by')->nullable();
            
            // Action Item Details
            $table->text('description');
            $table->text('context')->nullable();
            $table->enum('type', ['task', 'follow_up', 'decision', 'research', 'review'])->default('task');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('status', ['open', 'in_progress', 'completed', 'cancelled'])->default('open');
            
            // Scheduling & Deadlines
            $table->timestamp('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('estimated_hours')->nullable();
            $table->integer('actual_hours')->nullable();
            
            // AI Extraction Metadata
            $table->decimal('ai_confidence', 5, 3)->nullable();
            $table->text('extracted_text')->nullable(); // Original text from transcript
            $table->decimal('transcript_timestamp', 10, 3)->nullable(); // Timestamp in transcript
            $table->string('speaker_name')->nullable();
            
            // Relationships
            $table->uuid('parent_action_id')->nullable(); // For sub-tasks
            $table->json('tags')->nullable();
            $table->json('related_topics')->nullable();
            
            // Tracking & Analytics
            $table->boolean('auto_created')->default(false);
            $table->boolean('user_validated')->default(false);
            $table->integer('reminder_sent_count')->default(0);
            $table->timestamp('last_reminder_sent')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('meeting_id')->references('id')->on('meetings')->onDelete('cascade');
            $table->foreign('transcript_id')->references('id')->on('meeting_transcripts')->onDelete('cascade');
            $table->foreign('assigned_to')->references('id')->on('tenant_users')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('tenant_users')->onDelete('set null');
            $table->foreign('parent_action_id')->references('id')->on('action_items')->onDelete('cascade');
            
            $table->index(['tenant_id', 'status', 'priority']);
            $table->index(['assigned_to', 'status', 'due_date']);
            $table->index(['meeting_id', 'status']);
        });

        // ==============================================
        // WORKFLOW ORCHESTRATION
        // ==============================================
        
        Schema::create('workflows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('created_by');
            
            // Workflow Definition
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['meeting_follow_up', 'action_tracking', 'escalation', 'notification', 'custom']);
            $table->enum('trigger_type', ['manual', 'automatic', 'scheduled', 'condition_based']);
            $table->json('trigger_conditions')->nullable();
            
            // Workflow Steps
            $table->json('workflow_definition'); // DAG definition
            $table->json('default_settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_template')->default(false);
            
            // Usage & Performance
            $table->integer('execution_count')->default(0);
            $table->decimal('average_execution_time', 10, 2)->default(0);
            $table->decimal('success_rate', 5, 3)->default(0);
            $table->timestamp('last_executed_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('tenant_users')->onDelete('cascade');
            $table->index(['tenant_id', 'type', 'is_active']);
        });

        Schema::create('workflow_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workflow_id');
            $table->uuid('triggered_by')->nullable();
            $table->uuid('meeting_id')->nullable();
            
            // Execution Details
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->json('input_data')->nullable();
            $table->json('output_data')->nullable();
            $table->json('execution_steps')->nullable(); // Step-by-step execution log
            $table->json('error_details')->nullable();
            
            // Performance Metrics
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('execution_time_ms', 10, 2)->nullable();
            $table->integer('steps_completed')->default(0);
            $table->integer('total_steps')->default(0);
            
            // Context & Metadata
            $table->string('trigger_source')->nullable(); // What triggered this execution
            $table->json('context_data')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('workflow_id')->references('id')->on('workflows')->onDelete('cascade');
            $table->foreign('triggered_by')->references('id')->on('tenant_users')->onDelete('set null');
            $table->foreign('meeting_id')->references('id')->on('meetings')->onDelete('cascade');
            
            $table->index(['tenant_id', 'status']);
            $table->index(['workflow_id', 'status', 'started_at']);
        });

        // ==============================================
        // VECTOR STORAGE & AI EMBEDDINGS
        // ==============================================
        
        Schema::create('vector_embeddings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('vector_id')->unique(); // Pinecone vector ID
            
            // Source Information
            $table->enum('source_type', ['transcript', 'action_item', 'insight', 'meeting_summary']);
            $table->uuid('source_id'); // ID of the source record
            $table->text('content'); // Text content that was embedded
            $table->json('metadata')->nullable(); // Additional metadata for filtering
            
            // Vector Metadata
            $table->integer('dimensions')->default(1536); // OpenAI embedding dimensions
            $table->string('embedding_model', 100)->default('text-embedding-3-large');
            $table->decimal('embedding_cost', 8, 6)->nullable(); // Cost to generate
            
            // Performance Tracking
            $table->integer('search_count')->default(0); // How often this vector is retrieved
            $table->decimal('average_similarity_score', 5, 4)->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            
            // Pinecone Integration
            $table->boolean('synced_to_pinecone')->default(false);
            $table->timestamp('pinecone_synced_at')->nullable();
            $table->string('pinecone_namespace')->nullable();
            
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'source_type']);
            $table->index(['source_type', 'source_id']);
            $table->index('synced_to_pinecone');
        });

        // ==============================================
        // ANALYTICS & REPORTING
        // ==============================================
        
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable();
            
            // Event Details
            $table->string('event_type', 100); // meeting_completed, action_created, workflow_triggered, etc.
            $table->string('event_category', 50); // meeting, action, workflow, ai, etc.
            $table->json('event_data')->nullable(); // Flexible event data
            $table->json('event_metadata')->nullable(); // Additional context
            
            // Dimensions for Analysis
            $table->string('session_id')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('referrer')->nullable();
            
            // Performance Metrics
            $table->decimal('duration_ms', 10, 2)->nullable(); // How long the action took
            $table->boolean('success')->default(true);
            $table->json('error_data')->nullable();
            
            $table->timestamp('created_at');
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('tenant_users')->onDelete('set null');
            
            $table->index(['tenant_id', 'event_type', 'created_at']);
            $table->index(['tenant_id', 'event_category', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('dashboard_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            
            // Metric Definition
            $table->string('metric_name', 100);
            $table->string('metric_category', 50); // meetings, actions, workflows, ai, users
            $table->decimal('metric_value', 15, 4);
            $table->enum('metric_type', ['counter', 'gauge', 'histogram', 'rate']);
            
            // Time Dimensions
            $table->date('metric_date');
            $table->integer('metric_hour')->nullable(); // 0-23 for hourly metrics
            $table->enum('aggregation_period', ['hourly', 'daily', 'weekly', 'monthly']);
            
            // Additional Dimensions
            $table->json('dimensions')->nullable(); // For segmentation (user_tier, meeting_type, etc.)
            $table->json('metadata')->nullable();
            
            // Data Quality
            $table->boolean('is_estimated')->default(false); // For projected/estimated metrics
            $table->decimal('confidence_level', 5, 3)->nullable();
            $table->timestamp('calculated_at');
            
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            $table->unique(['tenant_id', 'metric_name', 'metric_date', 'metric_hour', 'aggregation_period'], 'unique_metric_period');
            $table->index(['tenant_id', 'metric_category', 'metric_date']);
        });

        // ==============================================
        // SYSTEM MONITORING & HEALTH
        // ==============================================
        
        Schema::create('system_health_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(); // null for system-wide metrics
            
            // Health Check Details
            $table->string('service_name', 100); // fireflies, pinecone, openai, database, etc.
            $table->string('metric_name', 100); // response_time, error_rate, throughput, etc.
            $table->decimal('metric_value', 15, 4);
            $table->string('metric_unit', 20)->nullable(); // ms, req/s, %, etc.
            
            // Status & Thresholds
            $table->enum('status', ['healthy', 'warning', 'critical', 'unknown'])->default('healthy');
            $table->decimal('warning_threshold', 15, 4)->nullable();
            $table->decimal('critical_threshold', 15, 4)->nullable();
            
            // Context
            $table->json('additional_data')->nullable();
            $table->text('notes')->nullable();
            
            $table->timestamp('measured_at');
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['service_name', 'metric_name', 'measured_at']);
            $table->index(['tenant_id', 'status', 'measured_at']);
        });

        // ==============================================
        // AUDIT TRAIL & COMPLIANCE
        // ==============================================
        
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable();
            
            // Audit Event Details
            $table->string('action', 100); // create, update, delete, view, export, etc.
            $table->string('resource_type', 100); // meeting, action_item, user, workflow, etc.
            $table->uuid('resource_id')->nullable();
            $table->text('description');
            
            // Change Tracking
            $table->json('old_values')->nullable(); // Before state
            $table->json('new_values')->nullable(); // After state
            $table->json('changed_fields')->nullable(); // List of changed fields
            
            // Request Context
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('session_id')->nullable();
            $table->json('request_data')->nullable(); // Request parameters
            
            // Compliance & Security
            $table->enum('sensitivity_level', ['public', 'internal', 'confidential', 'restricted'])->default('internal');
            $table->boolean('pii_involved')->default(false); // Personally Identifiable Information
            $table->integer('retention_years')->default(7); // How long to retain this log
            
            $table->timestamp('created_at');
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('tenant_users')->onDelete('set null');
            
            $table->index(['tenant_id', 'action', 'created_at']);
            $table->index(['tenant_id', 'resource_type', 'resource_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['pii_involved', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop tables in reverse order to handle foreign key constraints
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('system_health_metrics');
        Schema::dropIfExists('dashboard_metrics');
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('vector_embeddings');
        Schema::dropIfExists('workflow_executions');
        Schema::dropIfExists('workflows');
        Schema::dropIfExists('action_items');
        Schema::dropIfExists('meeting_insights');
        Schema::dropIfExists('meeting_transcripts');
        Schema::dropIfExists('meetings');
        Schema::dropIfExists('tenant_users');
        Schema::dropIfExists('tenants');
    }
};