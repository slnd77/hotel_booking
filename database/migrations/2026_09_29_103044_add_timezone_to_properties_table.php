<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The property's IANA timezone (e.g. Asia/Kolkata). Times are stored in UTC; this is
     * only used to show them in the property's local time and to know its business date.
     */
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('timezone', 64)->default('Asia/Kolkata')->after('currency_id');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
