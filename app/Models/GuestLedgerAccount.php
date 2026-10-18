<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A guest's ledger at a property: money held for the guest (a credit moved off a booking),
 * kept on its own folio and used on the guest's later bookings (see GuestLedger).
 */
class GuestLedgerAccount extends Model
{
    protected $fillable = [
        'property_id',
        'guest_id',
        'created_by',
    ];

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * @return MorphMany<Folio, $this>
     */
    public function folios(): MorphMany
    {
        return $this->morphMany(Folio::class, 'owner');
    }
}
