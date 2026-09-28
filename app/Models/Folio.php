<?php

namespace App\Models;

use App\Enums\FolioStatus;
use Database\Factories\FolioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Master financial container, owned by a SubBooking (one room), a Booking
 * (master / group bill) or a CompanyProfile (non-staying payer).
 */
class Folio extends Model
{
    /** @use HasFactory<FolioFactory> */
    use HasFactory;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'status',
        'currency',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FolioStatus::class,
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<SubFolio, $this>
     */
    public function subFolios(): HasMany
    {
        return $this->hasMany(SubFolio::class)->orderBy('window_number');
    }

    /**
     * @return HasManyThrough<Transaction, SubFolio, $this>
     */
    public function transactions(): HasManyThrough
    {
        return $this->hasManyThrough(Transaction::class, SubFolio::class);
    }

    /**
     * Debits minus credits across every window.
     */
    public function balance(): float
    {
        return Transaction::balanceOf($this->transactions()->getQuery());
    }
}
