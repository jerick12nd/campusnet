<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitored_devices', function (Blueprint $table) {
            // Marks rows created by `campusnet:demo-data`. Real monitoring modes never
            // count or display these, so simulated devices cannot pass as real ones.
            $table->boolean('is_demo')->default(false);
            $table->index('is_demo');
        });
    }

    public function down(): void
    {
        Schema::table('monitored_devices', function (Blueprint $table) {
            $table->dropIndex(['is_demo']);
            $table->dropColumn('is_demo');
        });
    }
};