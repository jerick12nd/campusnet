<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traffic_samples', function (Blueprint $table) {
            $table->id();

            $table->foreignId('monitored_device_id')
                ->constrained('monitored_devices')
                ->restrictOnDelete();
            $table->foreignId('network_interface_id')
                ->constrained('network_interfaces')
                ->restrictOnDelete();

            $table->timestamp('sampled_at');

            // Raw counters as reported by the device (null when the poll failed).
            $table->unsignedBigInteger('in_octets')->nullable();
            $table->unsignedBigInteger('out_octets')->nullable();

            // Mbps = (delta bytes * 8) / (elapsed seconds * 1,000,000).
            // decimal(12,4) holds up to 99,999,999.9999 Mbps with 4 decimals.
            $table->decimal('in_mbps', 12, 4)->nullable();
            $table->decimal('out_mbps', 12, 4)->nullable();
            $table->decimal('utilization_percent', 7, 2)->nullable();
            $table->unsignedInteger('elapsed_seconds')->nullable();

            $table->string('source', 16);              // mock | snmp | mikrotik
            $table->string('status', 16)->default('ok'); // ok | baseline | counter_reset | failed | invalid
            $table->boolean('is_successful')->default(true);
            $table->string('error_message', 255)->nullable();

            $table->timestamp('created_at')->nullable();

            // Hot paths: per-interface charts, dashboard windows, per-device views.
            $table->index(['network_interface_id', 'sampled_at']);
            $table->index(['monitored_device_id', 'sampled_at']);
            $table->index('sampled_at');
            $table->index(['source', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_samples');
    }
};