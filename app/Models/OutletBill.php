<?php

namespace App\Models;

use App\Enums\OutletBillStatus;
use Database\Factories\OutletBillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A point-of-sale bill of an outlet (see App\Services\OutletBills). A bill posted to a room
 * lands on the guest's folio; a bill settled at the outlet has its own folio, holding its
 * charges and the payment that settled it.
 */
class OutletBill extends Model
{
    /** @use HasFactory<OutletBillFactory> */
    use HasFactory;

    protected $fillable = [
        'property_id',
        'outlet_id',
        'bill_number',
        'business_date',
        'status',
        'guest_name',
        'table_number',
        'outlet_table_id',
        'split_from_id',
        'booking_id',
        'sub_booking_id',
        'sub_folio_id',
        'payment_id',
        'sub_total',
        'discount',
        'tax_total',
        'total',
        'notes',
        'created_by',
        'closed_at',
        'voided_at',
        'void_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OutletBillStatus::class,
            'business_date' => 'date',
            'sub_total' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'closed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * The table the bill is for (POS table plan).
     *
     * @return BelongsTo<OutletTable, $this>
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(OutletTable::class, 'outlet_table_id');
    }

    /**
     * The bill this one was split from.
     *
     * @return BelongsTo<OutletBill, $this>
     */
    public function splitFrom(): BelongsTo
    {
        return $this->belongsTo(OutletBill::class, 'split_from_id');
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * @return HasMany<OutletBillItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OutletBillItem::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<SubBooking, $this>
     */
    public function subBooking(): BelongsTo
    {
        return $this->belongsTo(SubBooking::class);
    }

    /**
     * The billing window the bill was charged to.
     *
     * @return BelongsTo<SubFolio, $this>
     */
    public function subFolio(): BelongsTo
    {
        return $this->belongsTo(SubFolio::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The bill's own folio, when it was settled at the outlet rather than posted to a room.
     *
     * @return MorphOne<Folio, $this>
     */
    public function folio(): MorphOne
    {
        return $this->morphOne(Folio::class, 'owner')->oldestOfMany();
    }

    /**
     * The kitchen tickets sent for the bill.
     *
     * @return HasMany<OutletKot, $this>
     */
    public function kots(): HasMany
    {
        return $this->hasMany(OutletKot::class)->orderBy('id');
    }

    /**
     * Ledger entries the bill posted (charges, tax, payment and any reversal).
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
