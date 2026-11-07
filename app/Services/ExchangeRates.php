<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * A property's exchange rates. A rate applies from its effective date until the next one;
 * a payment in another currency is converted at the rate of its transaction date.
 */
class ExchangeRates
{
    /**
     * One unit of the currency in the property's currency on the date, or null with no rate.
     */
    public function rateOn(Property $property, string $currencyCode, CarbonImmutable $date): ?float
    {
        $rate = ExchangeRate::query()
            ->where('property_id', $property->id)
            ->where('currency_code', strtoupper($currencyCode))
            ->whereDate('effective_date', '<=', $date->toDateString())
            ->orderByDesc('effective_date')
            ->value('rate');

        return $rate === null ? null : (float) $rate;
    }

    /**
     * Converts an amount in a foreign currency to the property's currency on the date.
     *
     * @return array{amount: float, rate: float}
     *
     * @throws ValidationException when there is no rate for the currency on the date
     */
    public function toPropertyCurrency(Property $property, string $currencyCode, float $amount, CarbonImmutable $date, string $field = 'currency_code'): array
    {
        $rate = $this->rateOn($property, $currencyCode, $date)
            ?? throw ValidationException::withMessages([$field => "There is no exchange rate for {$currencyCode} on {$date->format('d M Y')}. Add one under Settings → Exchange rates."]);

        return ['amount' => round($amount * $rate, 2), 'rate' => $rate];
    }

    /**
     * The latest rate of each currency on the date.
     *
     * @return list<array{currency_code: string, rate: float, effective_date: string}>
     */
    public function current(Property $property, ?CarbonImmutable $date = null): array
    {
        $date ??= CarbonImmutable::parse($property->today());

        return ExchangeRate::query()
            ->where('property_id', $property->id)
            ->whereDate('effective_date', '<=', $date->toDateString())
            ->orderBy('currency_code')
            ->orderByDesc('effective_date')
            ->get()
            ->unique('currency_code')
            ->map(fn (ExchangeRate $rate): array => ['currency_code' => $rate->currency_code, 'rate' => (float) $rate->rate, 'effective_date' => $rate->effective_date->toDateString()])
            ->values()
            ->all();
    }

    /**
     * Sets the currency's rate from the date (replacing a rate already set for that date).
     *
     * @throws ValidationException for the property's own currency
     */
    public function set(Property $property, string $currencyCode, float $rate, CarbonImmutable $from, User $user): ExchangeRate
    {
        $currencyCode = strtoupper($currencyCode);

        if ($currencyCode === Currency::forProperty($property)['code']) {
            throw ValidationException::withMessages(['currency_code' => 'This is the property\'s own currency.']);
        }

        $existing = ExchangeRate::query()
            ->where('property_id', $property->id)
            ->where('currency_code', $currencyCode)
            ->whereDate('effective_date', $from->toDateString())
            ->first();

        if ($existing !== null) {
            $existing->update(['rate' => $rate, 'created_by' => $user->id]);

            return $existing;
        }

        return ExchangeRate::create(['property_id' => $property->id, 'currency_code' => $currencyCode, 'effective_date' => $from->toDateString(), 'rate' => $rate, 'created_by' => $user->id]);
    }
}
