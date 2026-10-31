<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_alerts', function (Blueprint $table) {
            $table->id();

            $table->string('type', 32);
            $table->string('severity', 16);
            $table->string('status', 16)->default('active');
            $table->string('title', 160);
            $table->text('description')->nullable();

            $table->foreignId('monitored_device_id')->nullable()
                ->constrained('monitored_devices')->restrictOnDelete();
            $table->foreignId('network_interface_id')->nullable()
                ->constrained('network_interfaces')->restrictOnDelete();

            // One ongoing incident = one dedup_key (e.g. "utilization:iface:12").
            // The alert engine looks up an open alert by this key instead of
            // creating a new row on every polling cycle.
            $table->string('dedup_key', 120);
            $table->json('metadata')->nullable();

            $table->timestamp('triggered_at');
            $table->timestamp('last_seen_at')->nullable();

            $table->foreignId('acknowledged_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();

            $table->foreignId('resolved_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note', 255)->nullable();

            $table->timestamps();

            $table->index(['dedup_key', 'status']);
            $table->index(['status', 'severity']);
            $table->index('triggered_at');
            $table->index(['monitored_device_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_alerts');
    }
};