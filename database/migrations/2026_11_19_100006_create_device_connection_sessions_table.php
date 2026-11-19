<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // registered_device_id = NULL means the device is not registered (unidentified).
        // member_id and office_id are snapshots taken when the session was recorded, so
        // reassigning a device later does not rewrite history.
        Schema::create('device_connection_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registered_device_id')->nullable()->constrained('registered_devices')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('office_id')->nullable()->constrained('offices')->nullOnDelete();
            $table->foreignId('activity_data_source_id')->nullable()->constrained('activity_data_sources')->nullOnDelete();
            $table->foreignId('monitored_device_id')->nullable()->constrained('monitored_devices')->nullOnDelete();
            $table->string('mac_address', 17);
            $table->string('ip_address', 45)->nullable();
            $table->string('hostname')->nullable();
            $table->string('interface_name', 100)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('rx_bytes')->nullable(); // only when the source can measure it
            $table->unsignedBigInteger('tx_bytes')->nullable();
            $table->timestamps();

            $table->index(['mac_address', 'ended_at']);
            $table->index(['office_id', 'ended_at']);
            $table->index(['member_id', 'started_at']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_connection_sessions');
    }
};