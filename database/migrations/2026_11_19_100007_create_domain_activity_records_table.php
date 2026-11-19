<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Domain names only. No URLs, paths, page contents or credentials are ever stored.
        // member_id / office_id are filled only when the device was reliably identified.
        Schema::create('domain_activity_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registered_device_id')->nullable()->constrained('registered_devices')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('office_id')->nullable()->constrained('offices')->nullOnDelete();
            $table->foreignId('activity_data_source_id')->constrained('activity_data_sources')->restrictOnDelete();
            $table->string('domain', 253);
            $table->string('observation_type', 30);          // dns_query, proxy_request, firewall_connection
            $table->string('client_ip', 45)->nullable();
            $table->unsignedInteger('hit_count')->default(1); // for sources that aggregate
            $table->unsignedBigInteger('traffic_bytes')->nullable(); // null = not measurable
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->index(['domain', 'observed_at']);
            $table->index(['office_id', 'observed_at']);
            $table->index(['member_id', 'observed_at']);
            $table->index(['registered_device_id', 'observed_at']);
            $table->index('observed_at'); // used by the retention cleanup
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_activity_records');
    }
};