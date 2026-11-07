<?php

namespace App\Models;

use Database\Factories\OutletTableFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A table of an outlet: placed on its floor plan, billed on the POS, and with its own QR code
 * guests can order from (when the outlet takes QR orders).
 */
class OutletTable extends Model
{
    /** @use HasFactory<OutletTableFactory> */
    use HasFactory;

    public const SHAPES = ['square', 'round', 'rect'];

    protected $fillable = [
        'property_id',
        'outlet_id',
        'name',
        'seats',
        'area',
        'pos_x',
        'pos_y',
        'shape',
        'qr_token',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seats' => 'integer',
            'pos_x' => 'integer',
            'pos_y' => 'integer',
            'status' => 'boolean',
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
     * @return HasMany<OutletBill, $this>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(OutletBill::class);
    }

    /**
     * The token of the table's ordering QR code, made on first use; a renewed one stops the old
     * printed code working.
     */
    public function qrToken(bool $renew = false): string
    {
        if ($renew || blank($this->qr_token)) {
            $this->forceFill(['qr_token' => Str::random(32)])->save();
        }

        return (string) $this->qr_token;
    }
}
