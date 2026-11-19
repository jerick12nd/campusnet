<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_data_sources', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();      // e.g. mikrotik_dhcp
            $table->string('name');
            $table->string('type', 30);                // dhcp, dns, proxy, firewall, radius
            $table->text('description')->nullable();   // purpose and limitations, shown in the UI
            $table->foreignId('monitored_device_id')->nullable()
                ->constrained('monitored_devices')->nullOnDelete();
            $table->boolean('is_enabled')->default(false); // nothing collects until a Super Admin enables it
            $table->unsignedSmallInteger('retention_days')->default(90);
            $table->json('settings')->nullable();      // never store passwords here
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_data_sources');
    }
};