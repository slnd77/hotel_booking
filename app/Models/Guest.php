<?php

namespace App\Models;

use App\Services\ApplicationSettings;
use Database\Factories\GuestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Guest extends Model
{
    /** @use HasFactory<GuestFactory> */
    use HasFactory;

    /**
     * The company account goes with the guest, so a form saving the guest keeps the link.
     *
     * @var list<string>
     */
    protected $with = ['cityLedgerAccount'];

    protected $fillable = [
        'property_id',
        'tracker_number',
        'title',
        'first_name',
        'last_name',
        'phone_country_code',
        'phone',
        'emails',
        'gender',
        'dob',
        'nationality',
        'purpose_of_visit',
        'guest_preferences',
        'subscribe_newsletter',
        'anniversary',
        'frequent_flyer',
        'vehicle_number',
        'place_of_birth',
        'present_business_address',
        'address',
        'company',
        'city_ledger_account_id',
        'international_details',
    ];

    /**
     * Numbers each new guest when Application Settings → Guest → "Enable
     * Guest Tracker Number" is on, whichever screen created the guest.
     */
    protected static function booted(): void
    {
        static::creating(function (Guest $guest): void {
            if (blank($guest->tracker_number)) {
                $guest->tracker_number = app(ApplicationSettings::class)->nextGuestTrackerNumber();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'emails' => 'array',
            'dob' => 'date',
            'anniversary' => 'date',
            'subscribe_newsletter' => 'boolean',
            'address' => 'array',
            'company' => 'array',
            'international_details' => 'array',
        ];
    }

    /**
     * The guest's company as a City Ledger account (Add/Update Company).
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
     * @return BelongsToMany<Booking, $this>
     */
    public function bookings(): BelongsToMany
    {
        return $this->belongsToMany(Booking::class, 'booking_guests');
    }
}
