<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tv_displays', function (Blueprint $table): void {
            $table->boolean('is_reusable')->default(false)->after('name');
            // Encrypted with APP_KEY; only filled for reusable codes so a Super Administrator can show it again.
            $table->text('pairing_code')->nullable()->after('pairing_hash');
        });
    }

    public function down(): void
    {
        Schema::table('tv_displays', function (Blueprint $table): void {
            $table->dropColumn(['is_reusable', 'pairing_code']);
        });
    }
};