<?php

namespace App\Models;

use Database\Factories\BookingChannelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An OTA an OTA booking came from ("Selected OTA" on the booking form). Seeded by
 * BookingChannelSeeder. (Meta bookings use the property's metasearch listings instead.)
 */
class BookingChannel extends Model
{
    /** @use HasFactory<BookingChannelFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Active OTAs, by name.
     *
     * @param  Builder<BookingChannel>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('name');
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
