<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Night audit → Payment Reconciliation: who checked each payment against the till/bank, and when (UTC).
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dateTime('reconciled_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reconciled_by');
            $table->dropColumn('reconciled_at');
        });
    }
};
