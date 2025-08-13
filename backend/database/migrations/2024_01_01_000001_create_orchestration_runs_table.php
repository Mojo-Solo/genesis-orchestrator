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
        Schema::create('orchestration_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('correlation_id')->index();
            $table->string('mode')->default('standard'); // standard, stability, baseline, full
            $table->text('original_query');
            $table->enum('status', ['pending', 'planning', 'executing', 'completed', 'failed', 'terminated']);
            
            // Timing
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_ms')->nullable();
            
            // Metrics
            $table->integer('total_tokens')->default(0);
            $table->decimal('total_cost_usd', 10, 4)->default(0);
            $table->integer('steps_completed')->default(0);
            $table->integer('total_steps')->nullable();
            
            // Results
            $table->text('final_answer')->nullable();
            $table->decimal('stability_score', 5, 4)->nullable();
            $table->decimal('rcr_efficiency', 5, 4)->nullable();
            
            // Terminator tracking
            $table->boolean('terminator_triggered')->default(false);
            $table->string('terminator_reason')->nullable();
            $table->integer('terminated_at_step')->nullable();
            
            // Artifacts paths
            $table->string('artifacts_path')->nullable();
            $table->json('artifact_files')->nullable();
            
            // Configuration snapshot
            $table->json('config_snapshot')->nullable();
            $table->string('seed')->nullable();
            $table->decimal('temperature', 3, 2)->nullable();
            
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('stability_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orchestration_runs');
    }
};