<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // nullOnDelete keeps the log row if the account is ever removed;
            // actor_name is a snapshot so the record stays readable.
            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('actor_name', 120)->nullable();

            $table->string('action', 64);
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('description', 255)->nullable();

            // Non-secret context only. AuditLogger redacts secret-looking keys.
            $table->json('metadata')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            // Append-only: no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index('action');
            $table->index(['subject_type', 'subject_id']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};