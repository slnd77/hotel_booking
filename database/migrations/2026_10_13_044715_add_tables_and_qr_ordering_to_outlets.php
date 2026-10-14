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
        Schema::table('outlets', function (Blueprint $table) {
            // Guests may order from their table's QR code (staff accept each order on the POS).
            $table->boolean('qr_ordering')->default(false)->after('cash_drawer');
        });

        Schema::table('outlet_bills', function (Blueprint $table) {
            $table->foreignId('outlet_table_id')->nullable()->after('table_number')->constrained()->nullOnDelete();
            // A bill split from another keeps a link to it.
            $table->foreignId('split_from_id')->nullable()->after('outlet_table_id')->constrained('outlet_bills')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('outlet_bills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('split_from_id');
            $table->dropConstrainedForeignId('outlet_table_id');
        });

        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn('qr_ordering');
        });
    }
};
