<?php

namespace App\Models;

use App\Enums\BookingCheckInStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Services\CompanyBilling;
use App\Services\RoomStays;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

/**
 * @property int|null $created_by the user who created the booking (a users.id)
 * @property int|null $updated_by the user who last changed the booking (a users.id)
 */
class Booking extends Model
{
    public const SOURCE_DIRECT = 'direct';

    public const SOURCE_CORPORATE = 'corporate';

    public const SOURCE_TRAVEL_AGENT = 'travel_agent';

    public const SOURCE_OTA = 'ota';

    public const SOURCE_BOOKING_ENGINE = 'booking_engine';

    public const SOURCE_META = 'meta';

    public const SOURCES = [
        self::SOURCE_DIRECT => 'Direct',
        self::SOURCE_CORPORATE => 'Corporate',
        self::SOURCE_TRAVEL_AGENT => 'Agent',
        self::SOURCE_OTA => 'OTA',
        self::SOURCE_META => 'Meta',
        self::SOURCE_BOOKING_ENGINE => 'Booking Engine',
    ];

    protected $fillable = [
        'property_id',
        'guest_id',
        'agent_id',
        'booking_channel_id',
        'metasearch_platform_id',
        'booking_ref_id',
        'source',
        'type',
        'booking_type',
        'check_in',
        'check_out',
        'rooms_split',
        'check_in_time',
        'check_out_time',
        'same_plan_for_all_rooms',
        'pay_at_hotel',
        'rate_plan',
        'check_in_status',
        'status',
        'room_total',
        'discount',
        'promo_code',
        'taxes',
        'total',
        'outstanding',
        'room_bill_to',
        'extras_bill_to',
        'bill_room_to_guest_company',
        'bill_extras_to_guest_company',
        'city_ledger_account_id',
        'no_post',
        'special_instructions',
        'internal_notes',
        'copy_to',
        'inclusions',
        'hold_release_at',
        'hold_auto_release',
        'hold_reminders',
    ];

    protected static function booted(): void
    {
        // Who created / last changed the booking (nobody when the booking engine or a command does).
        static::creating(function (Booking $booking): void {
            $booking->created_by ??= auth()->user()?->id;
            $booking->updated_by ??= auth()->user()?->id;
        });

        static::updating(function (Booking $booking): void {
            $userId = auth()->user()?->id;

            if ($userId !== null) {
                $booking->updated_by = $userId;
            }
        });

        static::created(function (Booking $booking): void {
            Payment::logForBooking($booking);
        });

        static::updated(function (Booking $booking): void {
            if ($booking->wasChanged('status') && in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::ExpiredQuote], true)) {
                $booking->payments()->where('status', PaymentStatus::Pending)->update(['status' => PaymentStatus::Cancelled]);
            }

            // Rooms follow the booking's dates and booking-wide statuses.
            if ($booking->wasChanged(['check_in', 'check_out', 'status'])) {
                app(RoomStays::class)->followBooking($booking);
            }

            // Bill To Guest Company: the company's share moves to its City Ledger account at checkout.
            if ($booking->wasChanged('status') && $booking->status === BookingStatus::CheckedOut && $booking->city_ledger_account_id !== null) {
                $user = auth()->user();
                DB::afterCommit(fn () => app(CompanyBilling::class)->billOnCheckout($booking->fresh() ?? $booking, $user instanceof User ? $user : null));
            }
        });
    }

    /**
     * Enquiries whose release date & time has passed become expired quotes.
     *
     * @return int The number of enquiries that expired.
     */
    public static function expireOverdueEnquiries(): int
    {
        $expired = 0;

        static::query()
            ->where('status', BookingStatus::Enquiry)
            ->whereNotNull('hold_release_at')
            ->where('hold_release_at', '<=', now())
            ->each(function (Booking $booking) use (&$expired): void {
                $booking->update(['status' => BookingStatus::ExpiredQuote]);
                $expired++;
            });

        return $expired;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'check_in_status' => BookingCheckInStatus::class,
            'status' => BookingStatus::class,
            'check_in' => 'date',
            'check_out' => 'date',
            'rooms_split' => 'boolean',
            'same_plan_for_all_rooms' => 'boolean',
            'pay_at_hotel' => 'boolean',
            'room_total' => 'decimal:2',
            'discount' => 'decimal:2',
            'taxes' => 'decimal:2',
            'total' => 'decimal:2',
            'outstanding' => 'decimal:2',
            'addons_total' => 'decimal:2',
            'bill_room_to_guest_company' => 'boolean',
            'bill_extras_to_guest_company' => 'boolean',
            'no_post' => 'boolean',
            'copy_to' => 'array',
            'inclusions' => 'array',
            'hold_release_at' => 'datetime',
            'hold_auto_release' => 'boolean',
            'hold_reminders' => 'array',
        ];
    }

    public function setCheckInStatusAttribute(mixed $value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['check_in_status'] = null;

            return;
        }

        $enum = BookingCheckInStatus::tryFromNormalized($value);
        $this->attributes['check_in_status'] = $enum ? $enum->value : (is_string($value) ? $value : null);
    }

    /**
     * The company account billed for the booking (Bill To Guest Company).
     *
     * @return BelongsTo<CityLedgerAccount, $this>
     */
    public function cityLedgerAccount(): BelongsTo
    {
        return $this->belongsTo(CityLedgerAccount::class);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * The agency contact an agent booking came through.
     *
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        // A booking keeps its agent after the contact is (soft) deleted from the Agents database.
        return $this->belongsTo(Agent::class)->withTrashed();
    }

    /**
     * The OTA an OTA booking came from.
     *
     * @return BelongsTo<BookingChannel, $this>
     */
    public function bookingChannel(): BelongsTo
    {
        return $this->belongsTo(BookingChannel::class);
    }

    /**
     * The metasearch engine (one the property is listed on) a Meta booking came from.
     *
     * @return BelongsTo<SupportedMetasearchPlatform, $this>
     */
    public function metasearchPlatform(): BelongsTo
    {
        return $this->belongsTo(SupportedMetasearchPlatform::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The booking's rooms — its sub-bookings (one stay per room or bed).
     *
     * @return HasMany<SubBooking, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(SubBooking::class)->orderBy('sub_bookings.id');
    }

    /**
     * Add-on services sold on the booking (removed ones are soft deleted).
     *
     * @return HasMany<BookingAddon, $this>
     */
    public function addons(): HasMany
    {
        return $this->hasMany(BookingAddon::class)->orderBy('id');
    }

    /**
     * The guest's web check-in, once a link was made.
     *
     * @return HasOne<WebCheckin, $this>
     */
    public function webCheckin(): HasOne
    {
        return $this->hasOne(WebCheckin::class);
    }

    /**
     * @return HasMany<BookingAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(BookingAttachment::class);
    }

    /**
     * The primary booker.
     *
     * @return BelongsTo<Guest, $this>
     */
    public function primaryGuest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'guest_id');
    }

    /**
     * Child reservations: one per room or bed, each with its own dates and status.
     *
     * @return HasMany<SubBooking, $this>
     */
    public function subBookings(): HasMany
    {
        return $this->hasMany(SubBooking::class);
    }

    /**
     * Folios owned by the booking itself (master / group bill).
     *
     * @return MorphMany<Folio, $this>
     */
    public function folios(): MorphMany
    {
        return $this->morphMany(Folio::class, 'owner');
    }

    /**
     * Ids of the booking's folio and its rooms' sub-folios (searchable as "folio numbers").
     *
     * @return list<int>
     */
    public function folioIds(): array
    {
        return array_values($this->allFoliosQuery()->orderBy('id')->pluck('id')->all());
    }

    /**
     * Every folio this reservation is liable for: its master folios plus each
     * child room's folios. Company-owned folios are excluded — those are the company's debt.
     *
     * @return Builder<Folio>
     */
    public function allFoliosQuery(): Builder
    {
        return Folio::query()->where(function (Builder $query): void {
            $query->whereMorphedTo('owner', $this)
                ->orWhere(fn (Builder $query) => $query
                    ->where('owner_type', (new SubBooking)->getMorphClass())
                    ->whereIn('owner_id', $this->subBookings()->select('id')));
        });
    }

    /**
     * What the reservation still owes across all its folios: debits minus credits.
     * Routed charges are counted once, in the window they were posted to.
     */
    public function outstandingBalance(): float
    {
        return Transaction::balanceOf(
            Transaction::query()->whereHas('subFolio', fn (Builder $query) => $query->whereIn('folio_id', $this->allFoliosQuery()->select('id'))),
        );
    }

    /**
     * @return HasMany<PaymentLink, $this>
     */
    public function paymentLinks(): HasMany
    {
        return $this->hasMany(PaymentLink::class);
    }

    /**
     * @return BelongsToMany<Guest, $this>
     */
    public function guests(): BelongsToMany
    {
        return $this->belongsToMany(Guest::class, 'booking_guests');
    }

    /**
     * @return BelongsToMany<Payment, $this>
     */
    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class, 'bookings_payments_mappings')
            ->withPivot('tagged_amount')
            ->withTimestamps();
    }

    /**
     * @return HasMany<BookingPaymentMapping, $this>
     */
    public function bookingPaymentMappings(): HasMany
    {
        return $this->hasMany(BookingPaymentMapping::class);
    }

    /**
     * Direct payments referencing this booking ID.
     *
     * @return HasMany<Payment, $this>
     */
    public function directPayments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
