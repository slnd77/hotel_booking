<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The outlet's network ESC/POS printers (raw TCP, usually port 9100): one for bills, one for
     * kitchen tickets, and the paper the kitchen printer takes. Without a host, bills and tickets
     * print from the browser.
     */
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->string('printer_host')->nullable()->after('printer_setting');
            $table->unsignedSmallInteger('printer_port')->nullable()->after('printer_host');
            $table->string('kot_printer_setting')->default('thermal_80')->after('printer_port');
            $table->string('kot_printer_host')->nullable()->after('kot_printer_setting');
            $table->unsignedSmallInteger('kot_printer_port')->nullable()->after('kot_printer_host');
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn(['printer_host', 'printer_port', 'kot_printer_setting', 'kot_printer_host', 'kot_printer_port']);
        });
    }
};
