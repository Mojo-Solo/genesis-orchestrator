<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for tenant user associations
     */
    public function up(): void
    {
        Schema::create('tenant_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('user_id'); // Can be external ID from auth provider
            $table->string('email');
            $table->string('name');
            
            // User roles and permissions within tenant
            $table->enum('role', ['owner', 'admin', 'member', 'viewer'])->default('member');
            $table->json('permissions')->nullable(); // Custom permissions
            
            // User status within tenant
            $table->enum('status', ['active', 'invited', 'suspended', 'deleted'])->default('invited');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('invited_by')->nullable();
            
            // Usage tracking per user
            $table->integer('api_calls_today')->default(0);
            $table->integer('tokens_used_today')->default(0);
            $table->timestamp('last_active_at')->nullable();
            $table->string('last_ip_address')->nullable();
            
            // Security
            $table->boolean('mfa_enabled')->default(false);
            $table->timestamp('password_changed_at')->nullable();
            $table->integer('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            
            // Metadata
            $table->json('preferences')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign keys
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            // Indexes
            $table->unique(['tenant_id', 'email']);
            $table->index('user_id');
            $table->index('status');
            $table->index('role');
            $table->index('last_active_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_users');
    }
};