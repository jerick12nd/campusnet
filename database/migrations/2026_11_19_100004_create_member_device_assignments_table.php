<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per assignment. Reassigning a device closes the old row (unassigned_at)
        // and opens a new one, so the history of who used which device is never lost.
        // "One active assignment per device" is enforced in the service layer (Batch 2).
        Schema::create('member_device_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registered_device_id')->constrained('registered_devices')->restrictOnDelete();
            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable();
            $table->timestamps();

            // Explicit short names: MySQL limits identifiers to 64 characters.
            $table->index(['registered_device_id', 'unassigned_at'], 'mda_device_unassigned_idx');
            $table->index(['member_id', 'unassigned_at'], 'mda_member_unassigned_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_device_assignments');
    }
};