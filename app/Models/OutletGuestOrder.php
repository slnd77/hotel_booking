<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An order a guest placed from a table's QR code. It waits on the POS until staff accept it
 * (its items go on the table's open bill) or reject it.
 */
class OutletGuestOrder extends Model
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'property_id',
        'outlet_id',
        'outlet_table_id',
        'status',
        'guest_name',
        'notes',
        'items',
        'total',
        'outlet_bill_id',
        'handled_by',
        'handled_at',
        'reject_reason',
        'ip_address',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'items' => 'array',
            'total' => 'decimal:2',
            'handled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * @return BelongsTo<OutletTable, $this>
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(OutletTable::class, 'outlet_table_id');
    }

    /**
     * @return BelongsTo<OutletBill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(OutletBill::class, 'outlet_bill_id');
    }
}
