<?php

namespace App\Models;

use Database\Factories\CompanyProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A non-staying billing entity (corporate account, travel agent).
 */
class CompanyProfile extends Model
{
    /** @use HasFactory<CompanyProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'property_id',
        'city_ledger_account_id',
        'name',
        'tax_number',
        'email',
        'phone',
        'address',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'address' => 'array',
        ];
    }

    /**
     * The City Ledger account of this company.
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
     * @return MorphMany<Folio, $this>
     */
    public function folios(): MorphMany
    {
        return $this->morphMany(Folio::class, 'owner');
    }

    /**
     * Billing windows this company pays for on other folios.
     *
     * @return MorphMany<SubFolio, $this>
     */
    public function paidSubFolios(): MorphMany
    {
        return $this->morphMany(SubFolio::class, 'payer');
    }
}
