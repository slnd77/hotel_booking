<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The restaurant table a bill is for, printed on its kitchen tickets.
     */
    public function up(): void
    {
        Schema::table('outlet_bills', function (Blueprint $table) {
            $table->string('table_number', 20)->nullable()->after('guest_name');
        });
    }

    public function down(): void
    {
        Schema::table('outlet_bills', function (Blueprint $table) {
            $table->dropColumn('table_number');
        });
    }
};
