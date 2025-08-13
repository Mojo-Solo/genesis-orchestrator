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
        Schema::create('stability_tracking', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('test_group_id')->index(); // Groups runs for stability comparison
            $table->uuid('orchestration_run_id');
            $table->foreign('orchestration_run_id')->references('id')->on('orchestration_runs')->onDelete('cascade');
            
            // Run metadata
            $table->integer('run_number'); // 1-5 for stability testing
            $table->text('input_query');
            $table->string('seed_used');
            $table->decimal('temperature_used', 3, 2);
            
            // Plan stability
            $table->text('plan_signature'); // Hash of the decomposition plan
            $table->boolean('plan_matches_baseline')->nullable();
            $table->json('plan_decomposition')->nullable();
            
            // Route stability
            $table->text('route_signature'); // Hash of routing decisions
            $table->boolean('route_matches_baseline')->nullable();
            $table->json('route_set')->nullable();
            
            // Answer stability
            $table->text('answer_text')->nullable();
            $table->text('answer_signature')->nullable();
            $table->decimal('levenshtein_distance', 7, 4)->nullable();
            $table->decimal('answer_similarity', 5, 4)->nullable();
            
            // Latency stability
            $table->integer('latency_ms');
            $table->decimal('latency_variance_pct', 5, 2)->nullable();
            
            // Overall stability
            $table->decimal('stability_score', 5, 4)->nullable();
            $table->boolean('meets_986_target')->default(false);
            
            $table->timestamps();
            $table->index(['test_group_id', 'run_number']);
            $table->index('stability_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stability_tracking');
    }
};