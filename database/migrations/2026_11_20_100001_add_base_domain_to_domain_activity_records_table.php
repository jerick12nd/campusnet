<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "www.google.com" and "mail.google.com" both count under "google.com" in the summaries.
        Schema::table('domain_activity_records', function (Blueprint $table) {
            $table->string('base_domain', 253)->nullable()->after('domain');
            $table->index(['base_domain', 'observed_at'], 'dar_base_domain_observed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('domain_activity_records', function (Blueprint $table) {
            $table->dropIndex('dar_base_domain_observed_idx');
            $table->dropColumn('base_domain');
        });
    }
};