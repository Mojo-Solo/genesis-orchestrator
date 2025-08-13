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
        Schema::create('router_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('orchestration_run_id');
            $table->foreign('orchestration_run_id')->references('id')->on('orchestration_runs')->onDelete('cascade');
            
            // Algorithm metrics
            $table->string('algorithm')->default('RCR');
            $table->integer('total_tokens_routed');
            $table->integer('baseline_tokens')->nullable();
            $table->decimal('efficiency_gain', 5, 4)->nullable();
            
            // Performance metrics
            $table->integer('latency_p50_ms')->nullable();
            $table->integer('latency_p95_ms')->nullable();
            $table->integer('latency_p99_ms')->nullable();
            $table->decimal('quality_score', 5, 4)->nullable();
            
            // Routing decisions
            $table->json('routes')->nullable(); // Array of agent routes with weights
            $table->json('importance_scores')->nullable();
            $table->integer('iterations_to_converge')->nullable();
            
            // Semantic filtering
            $table->integer('semantic_filter_hits')->default(0);
            $table->integer('semantic_filter_misses')->default(0);
            $table->decimal('avg_similarity_score', 5, 4)->nullable();
            
            // Cache performance
            $table->integer('cache_hits')->default(0);
            $table->integer('cache_misses')->default(0);
            $table->decimal('cache_hit_rate', 5, 4)->nullable();
            
            $table->timestamps();
            $table->index('orchestration_run_id');
            $table->index('efficiency_gain');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('router_metrics');
    }
};