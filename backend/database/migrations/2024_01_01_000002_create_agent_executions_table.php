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
        Schema::create('agent_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('orchestration_run_id');
            $table->foreign('orchestration_run_id')->references('id')->on('orchestration_runs')->onDelete('cascade');
            
            $table->string('agent_name'); // planner, retriever, solver, critic, verifier, rewriter
            $table->integer('step_number');
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'skipped']);
            
            // Input/Output
            $table->text('input_prompt')->nullable();
            $table->text('output_response')->nullable();
            $table->json('context_used')->nullable();
            
            // Metrics
            $table->integer('tokens_used')->default(0);
            $table->integer('token_budget');
            $table->decimal('cost_usd', 8, 4)->default(0);
            $table->integer('latency_ms')->nullable();
            
            // RCR Routing
            $table->decimal('importance_score', 5, 4)->nullable();
            $table->decimal('routing_weight', 5, 4)->nullable();
            $table->json('role_keywords_matched')->nullable();
            
            // Quality metrics
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->boolean('quality_check_passed')->default(true);
            
            // Timing
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            
            $table->timestamps();
            $table->index(['orchestration_run_id', 'step_number']);
            $table->index(['agent_name', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_executions');
    }
};