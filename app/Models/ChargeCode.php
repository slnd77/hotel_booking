<?php

namespace App\Models;

use App\Enums\ChargeCategory;
use Database\Factories\ChargeCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An account in the chart of accounts (e.g. ROOM, CGST, FNB, PAY-CARD).
 */
class ChargeCode extends Model
{
    /** @use HasFactory<ChargeCodeFactory> */
    use HasFactory;

    public const ROOM = 'ROOM';

    public const TAX = 'TAX';

    public const DISCOUNT = 'DISC';

    public const ADJUSTMENT = 'ADJ';

    public const PAYMENT = 'PAY';

    public const REFUND = 'REFUND';

    /** A balance moved from one folio window to another (see FolioTransfers). */
    public const TRANSFER = 'XFER';

    /** Outlet (POS) sales and their tax: food & beverage, and other outlet services. */
    public const POS_FNB = 'POS-FNB';

    public const POS_FNB_TAX = 'POS-FNB-TAX';

    public const POS_OTHER = 'POS-OTH';

    public const POS_OTHER_TAX = 'POS-OTH-TAX';

    /** Add-on services sold on a booking, and their tax. */
    public const ADDON = 'ADDON';

    public const ADDON_TAX = 'ADDON-TAX';

    /** A credit note issued on an invoice: credits the booking's folio. */
    public const CREDIT_NOTE = 'CN';

    /** A booking's balance moved to a city ledger (direct bill) account. */
    public const CITY_LEDGER = 'CL';

    /** The cancellation policy's charge when a booking change takes nights or rooms off the bill. */
    public const CANCELLATION = 'CXL';

    /**
     * Shared codes (no property) used when mirroring the legacy booking totals and receipts.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const SYSTEM_CODES = [
        self::ROOM => ['Room Charge', 'room_and_tax'],
        self::TAX => ['Room Tax', 'room_and_tax'],
        self::DISCOUNT => ['Discount', 'room_and_tax'],
        self::ADJUSTMENT => ['Booking Adjustment', 'room_and_tax'],
        self::PAYMENT => ['Payment Received', 'payment'],
        self::REFUND => ['Refund', 'payment'],
        self::TRANSFER => ['Balance Transfer', 'transfer'],
        self::POS_FNB => ['Outlet Food & Beverage', 'fnb'],
        self::POS_FNB_TAX => ['Outlet Food & Beverage Tax', 'fnb'],
        self::POS_OTHER => ['Outlet Service', 'incidentals'],
        self::POS_OTHER_TAX => ['Outlet Service Tax', 'incidentals'],
        self::ADDON => ['Add-on Service', 'incidentals'],
        self::ADDON_TAX => ['Add-on Service Tax', 'incidentals'],
        self::CREDIT_NOTE => ['Credit Note', 'room_and_tax'],
        self::CITY_LEDGER => ['City Ledger Transfer', 'transfer'],
        self::CANCELLATION => ['Cancellation Charge', 'incidentals'],
    ];

    /**
     * A shared system code, created when missing.
     */
    public static function system(string $code): self
    {
        [$name, $category] = self::SYSTEM_CODES[$code];

        return self::query()->firstOrCreate(['property_id' => null, 'code' => $code], ['name' => $name, 'category' => $category, 'is_active' => true]);
    }

    protected $fillable = [
        'property_id',
        'code',
        'name',
        'category',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ChargeCategory::class,
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
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
