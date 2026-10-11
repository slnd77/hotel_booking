<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The random token in the outlet's public menu URL (its QR code), and whether a cash drawer
     * hangs off the bill printer (opened by the ESC/POS drawer kick).
     */
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->string('menu_token', 40)->nullable()->unique()->after('status');
            $table->boolean('cash_drawer')->default(false)->after('printer_port');
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropUnique(['menu_token']);
            $table->dropColumn(['menu_token', 'cash_drawer']);
        });
    }
};
