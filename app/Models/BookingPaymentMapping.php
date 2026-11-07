<?php

namespace App\Models;

use App\Services\GeneralLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class BookingPaymentMapping extends Model
{
    protected $table = 'bookings_payments_mappings';

    protected $fillable = [
        'booking_id',
        'payment_id',
        'tagged_amount',
    ];

    protected static function booted(): void
    {
        // Tagging (or untagging) moves the receipt in or out of Unallocated Receipts.
        $trueUp = function (BookingPaymentMapping $mapping): void {
            DB::afterCommit(function () use ($mapping): void {
                if ($payment = Payment::query()->find($mapping->payment_id)) {
                    app(GeneralLedger::class)->trueUpReceipt($payment);
                }
            });
        };

        static::saved($trueUp);
        static::deleted($trueUp);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tagged_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
