<?php

namespace App\Models;

use Database\Factories\SubFolioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A billing window of a folio (e.g. "Window A - Room & Tax"), optionally paid by
 * someone other than the folio owner.
 */
class SubFolio extends Model
{
    /** @use HasFactory<SubFolioFactory> */
    use HasFactory;

    protected $fillable = [
        'folio_id',
        'window_number',
        'window_name',
        'payer_type',
        'payer_id',
    ];

    /**
     * @return BelongsTo<Folio, $this>
     */
    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    /**
     * Guest or CompanyProfile settling this window; null means the folio owner pays.
     *
     * @return MorphTo<Model, $this>
     */
    public function payer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Rules redirecting other rooms' charges into this window.
     *
     * @return HasMany<RoutingRule, $this>
     */
    public function incomingRoutingRules(): HasMany
    {
        return $this->hasMany(RoutingRule::class, 'target_sub_folio_id');
    }

    public function balance(): float
    {
        return Transaction::balanceOf($this->transactions()->getQuery());
    }
}
