<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('channex_rate_mappings', function (Blueprint $table) {
            // A package sold as its own Channex rate plan (its id in rate_settings_packages).
            $table->unsignedInteger('package_id')->nullable()->after('adults');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channex_rate_mappings', function (Blueprint $table) {
            $table->dropColumn('package_id');
        });
    }
};
