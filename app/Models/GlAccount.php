<?php

namespace App\Models;

use App\Enums\GlAccountType;
use Database\Factories\GlAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An account in a property's general ledger (Cash, Guest AR, Room Revenue, Tax Payable, …).
 */
class GlAccount extends Model
{
    /** @use HasFactory<GlAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'property_id',
        'code',
        'name',
        'type',
        'system_key',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => GlAccountType::class,
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
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
