<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A catalogue item's tax rate (from the outlet's Tax Settings) and its SAC/HSN code for tax invoices.
     */
    public function up(): void
    {
        Schema::table('outlet_items', function (Blueprint $table) {
            $table->foreignId('outlet_tax_rate_id')->nullable()->after('tax_category_id')->constrained()->nullOnDelete();
            $table->string('sac_hsn_code', 20)->nullable()->after('outlet_tax_rate_id');
        });
    }

    public function down(): void
    {
        Schema::table('outlet_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('outlet_tax_rate_id');
            $table->dropColumn('sac_hsn_code');
        });
    }
};
