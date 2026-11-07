<?php

namespace App\Models;

use Database\Factories\CityLedgerAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A city ledger (direct bill) account: a company or agency billed on credit. Its balance is
 * the sum of its entries.
 */
class CityLedgerAccount extends Model
{
    /** @use HasFactory<CityLedgerAccountFactory> */
    use HasFactory;

    public const TYPES = ['company' => 'Company', 'agency' => 'Travel agency', 'other' => 'Other'];

    protected $fillable = [
        'property_id',
        'name',
        'code',
        'account_type',
        'agency_id',
        'tax_number',
        'contact_name',
        'email',
        'phone',
        'address',
        'credit_limit',
        'payment_terms_days',
        'is_active',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'payment_terms_days' => 'integer',
            'is_active' => 'boolean',
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
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return HasMany<CityLedgerEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(CityLedgerEntry::class);
    }

    /**
     * What the account owes now.
     */
    public function balance(): float
    {
        return round((float) $this->entries()->sum('amount'), 2);
    }
}
