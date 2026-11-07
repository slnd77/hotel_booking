<?php

namespace App\Models;

use App\Enums\WaitlistStatus;
use Database\Factories\WaitlistRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hold request for sold-out dates (Databases → Waitlist): who wants what, for when. It
 * turns "available" when inventory frees up and is converted into a booking by staff.
 */
class WaitlistRequest extends Model
{
    /** @use HasFactory<WaitlistRequestFactory> */
    use HasFactory;

    public const SOURCE_FRONT_DESK = 'front_desk';

    public const SOURCE_BOOKING_ENGINE = 'booking_engine';

    protected $fillable = [
        'property_id',
        'room_category_id',
        'guest_id',
        'booking_id',
        'guest_name',
        'phone',
        'email',
        'check_in',
        'check_out',
        'rooms',
        'adults',
        'children',
        'notes',
        'source',
        'status',
        'available_since',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'status' => WaitlistStatus::class,
            'available_since' => 'datetime',
        ];
    }

    /**
     * Requests still looking for rooms.
     *
     * @param  Builder<WaitlistRequest>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', WaitlistStatus::open());
    }

    public function isOpen(): bool
    {
        return in_array($this->status, WaitlistStatus::open(), true);
    }

    public function nights(): int
    {
        return max(1, (int) $this->check_in->diffInDays($this->check_out));
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<RoomCategory, $this>
     */
    public function roomCategory(): BelongsTo
    {
        return $this->belongsTo(RoomCategory::class);
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
