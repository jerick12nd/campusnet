<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->restrictOnDelete();
            $table->string('full_name');
            $table->string('employee_id', 50)->nullable()->unique();
            $table->string('position')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['office_id', 'is_active']);
            $table->index('full_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};