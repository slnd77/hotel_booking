<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $fillable = [
        'code',
        'name',
    ];

    /** Used when a property has no currency configured. */
    public const DEFAULT_CODE = 'INR';

    /**
     * The currency's symbol (e.g. ₹ for INR), from the countries that use it; the code
     * itself when no country lists a symbol.
     */
    public static function symbolFor(?string $code): string
    {
        $code = $code ?: self::DEFAULT_CODE;

        return Country::query()->where('currency', $code)->whereNotNull('currency_symbol')->value('currency_symbol') ?: $code;
    }

    /**
     * The configured currency of a property, as the admin screens and printouts show it.
     *
     * @return array{code: string, symbol: string}
     */
    public static function forProperty(?Property $property): array
    {
        $code = $property?->currency?->code ?: self::DEFAULT_CODE;

        return ['code' => $code, 'symbol' => self::symbolFor($code)];
    }
}
