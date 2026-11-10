<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_networks', function (Blueprint $table) {
            $table->id();
            $table->string('office');
            $table->string('cidr');   // pananglitan 192.168.1.0/24
            $table->timestamps();
        });

        Schema::create('scan_runs', function (Blueprint $table) {
            $table->id();
            $table->string('office');
            $table->string('cidr');
            $table->unsignedSmallInteger('hosts_up');
            $table->unsignedInteger('duration_ms');
            $table->timestamp('scanned_at');

            $table->index(['office', 'scanned_at']);
        });

        Schema::create('scan_hosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_run_id')->constrained()->cascadeOnDelete();
            $table->string('ip', 45);
            $table->string('mac', 17)->nullable();
            $table->string('vendor')->nullable();
            $table->string('hostname')->nullable();
            $table->boolean('random_mac')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_hosts');
        Schema::dropIfExists('scan_runs');
        Schema::dropIfExists('office_networks');
    }
};