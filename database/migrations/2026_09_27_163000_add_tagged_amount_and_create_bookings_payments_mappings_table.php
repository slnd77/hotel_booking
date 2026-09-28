<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('tagged_amount', 12, 2)->default(0)->after('total_txn_amount');
            $table->decimal('untagged_amount', 12, 2)->default(0)->after('tagged_amount');
        });

        Schema::create('bookings_payments_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->decimal('tagged_amount', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['booking_id', 'payment_id']);
        });

        // Restore payment 11 if it was zeroed out by the bug
        $payment11 = DB::table('payments')->where('id', 11)->first();
        if ($payment11 && (float) $payment11->total_txn_amount == 0.0) {
            $booking11 = DB::table('bookings')->where('id', 11)->first();
            if ($booking11) {
                DB::table('payments')->where('id', 11)->update([
                    'total_txn_amount' => 2891.00,
                    'status' => 'Received',
                ]);
            }
        }

        // Backfill payments tagged_amount and bookings_payments_mappings from existing payments
        $payments = DB::table('payments')->get();
        foreach ($payments as $payment) {
            $amount = (float) $payment->total_txn_amount;
            $tagged = $payment->booking_id ? $amount : 0.0;
            $untagged = max(0.0, $amount - $tagged);

            DB::table('payments')->where('id', $payment->id)->update([
                'tagged_amount' => $tagged,
                'untagged_amount' => $untagged,
            ]);

            if ($payment->booking_id && $tagged > 0) {
                DB::table('bookings_payments_mappings')->insertOrIgnore([
                    'booking_id' => $payment->booking_id,
                    'payment_id' => $payment->id,
                    'tagged_amount' => $tagged,
                    'created_at' => $payment->created_at ?? now(),
                    'updated_at' => $payment->updated_at ?? now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings_payments_mappings');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['tagged_amount', 'untagged_amount']);
        });
    }
};
