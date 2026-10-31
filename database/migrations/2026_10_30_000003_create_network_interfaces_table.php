<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_interfaces', function (Blueprint $table) {
            $table->id();
            // RESTRICT: a device that still owns interfaces cannot be hard-deleted.
            $table->foreignId('monitored_device_id')
                ->constrained('monitored_devices')
                ->restrictOnDelete();

            $table->unsignedInteger('if_index');
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->string('role', 16)->default('other');
            $table->boolean('is_monitored')->default(true);

            $table->string('oper_status', 16)->nullable(); // up, down, testing, ...
            $table->unsignedBigInteger('speed_mbps')->nullable();

            // Last raw counters, used as the baseline for the next rate calculation.
            $table->unsignedBigInteger('last_in_octets')->nullable();
            $table->unsignedBigInteger('last_out_octets')->nullable();
            $table->timestamp('last_polled_at')->nullable();

            $table->timestamps();

            $table->unique(['monitored_device_id', 'if_index']);
            $table->index(['role', 'is_monitored']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_interfaces');
    }
};