<?php

namespace App\Models;

use Database\Factories\AddonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An add-on service of a property, sold with bookings (see App\Services\BookingAddons).
 */
class Addon extends Model
{
    /** @use HasFactory<AddonFactory> */
    use HasFactory;

    /** How the price counts, and its label. @var array<string, string> */
    public const PRICING = [
        'per_booking' => 'Per booking',
        'per_night' => 'Per night',
        'per_guest' => 'Per guest',
        'per_guest_per_night' => 'Per guest per night',
    ];

    protected $fillable = [
        'property_id',
        'name',
        'description',
        'pricing',
        'price',
        'tax_rate',
        'sac_hsn_code',
        'show_on_booking_engine',
        'status',
        'display_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'show_on_booking_engine' => 'boolean',
            'status' => 'boolean',
            'display_order' => 'integer',
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
     * @return array{id: int, name: string, description: string|null, pricing: string, pricing_label: string, price: float, tax_rate: float}
     */
    public function toOption(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'pricing' => $this->pricing,
            'pricing_label' => self::PRICING[$this->pricing] ?? $this->pricing,
            'price' => (float) $this->price,
            'tax_rate' => (float) $this->tax_rate,
        ];
    }
}
