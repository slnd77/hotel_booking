<?php

namespace App\Models;

use App\Enums\OutletType;
use Database\Factories\OutletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A point of sale of a property (restaurant, laundry, spa…) with its own catalogue and price plans.
 */
class Outlet extends Model
{
    /** @use HasFactory<OutletFactory> */
    use HasFactory;

    /** @var array<string, string> */
    public const PRINTER_SETTINGS = [
        'a4' => 'A4 Paper',
        'thermal_80' => 'Thermal 80mm',
        'thermal_58' => 'Thermal 58mm',
    ];

    protected $fillable = [
        'property_id',
        'name',
        'type',
        'printer_setting',
        'printer_host',
        'printer_port',
        'cash_drawer',
        'qr_ordering',
        'kot_printer_setting',
        'kot_printer_host',
        'kot_printer_port',
        'display_order',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OutletType::class,
            'status' => 'boolean',
            'cash_drawer' => 'boolean',
            'qr_ordering' => 'boolean',
            'printer_port' => 'integer',
            'kot_printer_port' => 'integer',
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
     * @return HasMany<OutletDisplayCategory, $this>
     */
    public function displayCategories(): HasMany
    {
        return $this->hasMany(OutletDisplayCategory::class)->orderBy('display_order')->orderBy('id');
    }

    /**
     * @return HasMany<OutletItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OutletItem::class)->orderBy('display_order')->orderBy('id');
    }

    /**
     * The outlet's tables (POS table plan).
     *
     * @return HasMany<OutletTable, $this>
     */
    public function tables(): HasMany
    {
        return $this->hasMany(OutletTable::class)->orderBy('area')->orderBy('name');
    }

    /**
     * @return HasMany<OutletTaxRate, $this>
     */
    public function taxRates(): HasMany
    {
        return $this->hasMany(OutletTaxRate::class)->orderBy('rate')->orderBy('id');
    }

    /**
     * The token of the outlet's public menu URL, made on first use. A new one (after a QR code
     * leaked) stops the old QR codes working.
     */
    public function menuToken(bool $renew = false): string
    {
        if ($renew || ! $this->menu_token) {
            $this->forceFill(['menu_token' => Str::random(32)])->save();
        }

        return $this->menu_token;
    }

    /**
     * The paper width in characters of a thermal printer setting (58mm or 80mm, ESC/POS font A),
     * null for A4.
     */
    public static function receiptColumns(string $printerSetting): ?int
    {
        return match ($printerSetting) {
            'thermal_58' => 32,
            'thermal_80' => 48,
            default => null,
        };
    }

    /**
     * @return HasMany<OutletPricePlan, $this>
     */
    public function pricePlans(): HasMany
    {
        return $this->hasMany(OutletPricePlan::class)->orderBy('start_date')->orderBy('id');
    }
}
