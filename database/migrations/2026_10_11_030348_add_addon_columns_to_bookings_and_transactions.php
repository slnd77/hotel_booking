<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The booking's add-ons total (tax included; the room total stays room-only), and the
     * add-on behind a ledger entry.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('addons_total', 12, 2)->default(0)->after('total');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('booking_addon_id')->nullable()->after('outlet_bill_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_addon_id');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('addons_total');
        });
    }
};
