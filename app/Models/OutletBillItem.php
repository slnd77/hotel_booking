<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a POS bill, with the name, price and tax rate it was sold at.
 */
class OutletBillItem extends Model
{
    protected $fillable = [
        'outlet_bill_id',
        'outlet_item_id',
        'name',
        'sac_hsn_code',
        'quantity',
        'unit_price',
        'amount',
        'tax_rate',
        'tax_amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<OutletBill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(OutletBill::class, 'outlet_bill_id');
    }

    /**
     * @return BelongsTo<OutletItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(OutletItem::class, 'outlet_item_id');
    }
}
