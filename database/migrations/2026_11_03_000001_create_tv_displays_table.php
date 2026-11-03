<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tv_displays', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            // Only SHA-256 hashes are stored. The raw code and token are never saved.
            $table->char('pairing_hash', 64)->nullable()->unique();
            $table->timestamp('pairing_expires_at')->nullable();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_seen_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_displays');
    }
};