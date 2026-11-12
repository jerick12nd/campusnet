<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_checks', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('target', 100);
            $table->boolean('is_up');
            $table->unsignedTinyInteger('loss_percent');
            $table->unsignedInteger('avg_ms')->nullable();
            $table->unsignedInteger('max_ms')->nullable();
            $table->timestamp('checked_at');

            $table->index(['target', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_checks');
    }
};