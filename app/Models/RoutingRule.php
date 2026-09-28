<?php

namespace App\Models;

use App\Enums\ChargeCategory;
use Database\Factories\RoutingRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sends charges of one category that a room generates to another billing window
 * (e.g. every room's room & tax to the group master folio).
 */
class RoutingRule extends Model
{
    /** @use HasFactory<RoutingRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'source_sub_booking_id',
        'target_sub_folio_id',
        'charge_category',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'charge_category' => ChargeCategory::class,
        ];
    }

    /**
     * @return BelongsTo<SubBooking, $this>
     */
    public function sourceSubBooking(): BelongsTo
    {
        return $this->belongsTo(SubBooking::class, 'source_sub_booking_id');
    }

    /**
     * @return BelongsTo<SubFolio, $this>
     */
    public function targetSubFolio(): BelongsTo
    {
        return $this->belongsTo(SubFolio::class, 'target_sub_folio_id');
    }

    public function appliesTo(ChargeCategory $category): bool
    {
        return $this->charge_category === ChargeCategory::AllCharges || $this->charge_category === $category;
    }
}
