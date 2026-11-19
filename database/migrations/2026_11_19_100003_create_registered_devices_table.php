<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registered_devices', function (Blueprint $table) {
            $table->id();
            // Only for shared equipment (office printer, etc.). A personal device gets its
            // office from the member it is assigned to.
            $table->foreignId('office_id')->nullable()->constrained('offices')->nullOnDelete();
            $table->string('name');
            $table->string('device_type', 30)->default('other');
            $table->string('mac_address', 17)->unique(); // stored as AA:BB:CC:DD:EE:FF
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['office_id', 'device_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registered_devices');
    }
};