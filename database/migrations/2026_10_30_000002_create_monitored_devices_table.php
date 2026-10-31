<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitored_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('management_ip', 45); // fits IPv6
            $table->string('vendor', 80)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('device_type', 24)->default('router');
            $table->string('location', 120)->nullable();
            $table->text('description')->nullable();

            $table->boolean('is_enabled')->default(true);
            $table->string('snmp_version', 8)->default('v3');
            $table->unsignedSmallInteger('snmp_port')->default(161);
            $table->unsignedInteger('polling_interval_seconds')->default(60);

            // Laravel "encrypted:array" cast: SNMP community / SNMPv3 users and
            // keys / API tokens. Stored as ciphertext, hidden from serialization.
            $table->text('credentials')->nullable();

            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('last_successful_poll_at')->nullable();
            $table->string('last_status', 16)->default('unknown');
            $table->text('last_error')->nullable();

            $table->timestamps();
            // Devices are archived, never silently erased, so history stays intact.
            $table->softDeletes();

            $table->index('management_ip');
            $table->index('location');
            $table->index(['is_enabled', 'last_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitored_devices');
    }
};