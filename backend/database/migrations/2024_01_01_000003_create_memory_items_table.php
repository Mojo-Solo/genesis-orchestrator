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
        Schema::create('memory_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('orchestration_run_id');
            $table->foreign('orchestration_run_id')->references('id')->on('orchestration_runs')->onDelete('cascade');
            
            $table->string('role'); // planner, retriever, solver, etc.
            $table->text('content');
            $table->json('tags')->nullable(); // task_id, type, etc.
            
            // Vector storage
            $table->string('vector_id')->nullable()->index();
            $table->json('embedding')->nullable();
            $table->decimal('semantic_similarity', 5, 4)->nullable();
            
            // Retrieval tracking
            $table->integer('retrieval_count')->default(0);
            $table->timestamp('last_retrieved_at')->nullable();
            $table->decimal('relevance_score', 5, 4)->nullable();
            
            // Memory state
            $table->enum('state', ['pre', 'active', 'post', 'archived']);
            $table->boolean('is_persistent')->default(false);
            
            $table->timestamps();
            $table->index(['orchestration_run_id', 'state']);
            $table->index(['role', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('memory_items');
    }
};