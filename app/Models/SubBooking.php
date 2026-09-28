<?php

namespace App\Models;

use App\Enums\SubBookingStatus;
use App\Jobs\RefreshWaitlists;
use App\Services\DormBeds;
use App\Services\Housekeeping;
use App\Services\RoomStays;
use Database\Factories\SubBookingFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Child reservation: one unit (room or bed) for its own dates and stay status,
 * under a parent booking.
 */
class SubBooking extends Model
{
    /** @use HasFactory<SubBookingFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'inventory_unit_id',
        'room_id',
        'room_category_id',
        'check_in_date',
        'check_out_date',
        'status',
        'checked_in_at',
        'checked_out_at',
        'meal_plan',
        'package_id',
        'package_name',
        'adults',
        'children',
        'infants',
        'rate_per_night',
    ];

    protected static function booted(): void
    {
        // A room added to a booking takes the booking's dates and a matching status.
        static::creating(function (SubBooking $stay): void {
            $booking = $stay->booking ?? Booking::query()->find($stay->booking_id);

            if ($booking === null) {
                return;
            }

            $stay->check_in_date ??= $booking->check_in;
            $stay->check_out_date ??= $booking->check_out;
            $stay->status ??= RoomStays::statusForBooking($booking->status);

            app(DormBeds::class)->assignBed($stay);
        });

        // A dorm stay that moves room or dates (or starts occupying) is put on a free bed.
        static::updating(function (SubBooking $stay): void {
            if ($stay->isDirty(['inventory_unit_id', 'check_in_date', 'check_out_date', 'status'])) {
                app(DormBeds::class)->assignBed($stay);
            }
        });

        // A guest in house moved rooms: the room they left takes the housekeeping "Room change default".
        static::updated(function (SubBooking $stay): void {
            if (! $stay->wasChanged('inventory_unit_id') || $stay->status !== SubBookingStatus::CheckedIn) {
                return;
            }

            $vacatedRoomId = InventoryUnit::query()->whereKey($stay->getOriginal('inventory_unit_id'))->value('room_id');

            if ($vacatedRoomId && $property = $stay->booking?->property) {
                app(Housekeeping::class)->markVacatedByRoomChange($property, (int) $vacatedRoomId);
            }
        });

        // Inventory freed up (a stay cancelled, shortened or removed): re-check the waitlist.
        static::updated(function (SubBooking $stay): void {
            $freesInventory = ($stay->wasChanged('status') && ! in_array($stay->status, SubBookingStatus::occupying(), true))
                || $stay->wasChanged(['check_in_date', 'check_out_date', 'room_category_id']);

            if ($freesInventory && $propertyId = $stay->booking?->property_id) {
                RefreshWaitlists::dispatch((int) $propertyId);
            }
        });

        static::deleted(function (SubBooking $stay): void {
            if ($propertyId = $stay->booking?->property_id) {
                RefreshWaitlists::dispatch((int) $propertyId);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'status' => SubBookingStatus::class,
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'rate_per_night' => 'decimal:2',
        ];
    }

    /**
     * The physical room this stay is in (its unit's room), or null while unassigned.
     * Setting it assigns the room's inventory unit.
     *
     * @return Attribute<int|null, int|null>
     */
    protected function roomId(): Attribute
    {
        return Attribute::make(
            get: fn (): ?int => $this->inventory_unit_id === null ? null : $this->inventoryUnit?->room_id,
            set: fn (?int $roomId): array => ['inventory_unit_id' => $roomId === null ? null : InventoryUnit::forRoom($roomId)->id],
        );
    }

    /**
     * @return HasOneThrough<Room, InventoryUnit, $this>
     */
    public function room(): HasOneThrough
    {
        return $this->hasOneThrough(Room::class, InventoryUnit::class, 'id', 'id', 'inventory_unit_id', 'room_id');
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<InventoryUnit, $this>
     */
    public function inventoryUnit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class);
    }

    /**
     * @return BelongsTo<RoomCategory, $this>
     */
    public function roomCategory(): BelongsTo
    {
        return $this->belongsTo(RoomCategory::class);
    }

    /**
     * @return MorphMany<Folio, $this>
     */
    public function folios(): MorphMany
    {
        return $this->morphMany(Folio::class, 'owner');
    }

    /**
     * The room's own (first) folio.
     *
     * @return MorphOne<Folio, $this>
     */
    public function folio(): MorphOne
    {
        return $this->morphOne(Folio::class, 'owner')->oldestOfMany();
    }

    /**
     * @return HasMany<RoutingRule, $this>
     */
    public function routingRules(): HasMany
    {
        return $this->hasMany(RoutingRule::class, 'source_sub_booking_id');
    }

    /**
     * Charges this room generated, wherever routing posted them.
     *
     * @return HasMany<Transaction, $this>
     */
    public function generatedTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'source_sub_booking_id');
    }
}
