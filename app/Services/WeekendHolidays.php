<?php

namespace App\Services;

use App\Models\MetaField;
use App\Models\Property;
use Carbon\CarbonInterface;

/**
 * Properties → Policies → Weekends & Holidays: the property's weekend days and the
 * holidays, long weekends and events shown on the Smart Chart and booking form calendars.
 */
class WeekendHolidays
{
    public const KEY = 'weekend_holidays';

    /** @var list<string> */
    public const TYPES = ['national_holiday', 'regional_holiday', 'festival', 'long_weekend', 'event'];

    /** Types saved before the list grew, mapped onto the current ones. */
    private const LEGACY_TYPES = ['holiday' => 'national_holiday'];

    /**
     * @return array{weekend_days: list<int>, holidays: list<array{name: string, type: string, description: string, from: string, to: string, day_wise: bool, days: list<array{date: string, name: string, type: string, description: string}>}>}
     */
    public function get(Property $property): array
    {
        return app(PropertySettings::class)->remember($property, 'weekend_holidays', fn (): array => $this->resolve($property));
    }

    /**
     * @return array{weekend_days: list<int>, holidays: list<array{name: string, type: string, description: string, from: string, to: string, day_wise: bool, days: list<array{date: string, name: string, type: string, description: string}>}>}
     */
    private function resolve(Property $property): array
    {
        $data = app(PropertySettings::class)->json($property, self::KEY) ?? [];

        $holidays = ($data['holidays_enabled'] ?? false) ? (array) ($data['holidays'] ?? []) : [];

        return [
            // 0 = Monday … 6 = Sunday; Saturday and Sunday until the property picks its own.
            'weekend_days' => array_values(array_map('intval', (array) ($data['weekend_days'] ?? [5, 6]))),
            'holidays' => array_values(array_map(
                fn (array $holiday): array => [
                    ...$this->detail($holiday),
                    'from' => (string) $holiday['from'],
                    'to' => (string) $holiday['to'],
                    'day_wise' => (bool) ($holiday['day_wise'] ?? false),
                    'days' => array_values(array_map(
                        fn (array $day): array => ['date' => (string) $day['date'], ...$this->detail($day)],
                        array_filter((array) ($holiday['days'] ?? []), fn (mixed $day): bool => is_array($day) && isset($day['date'])),
                    )),
                ],
                array_filter($holidays, fn (mixed $holiday): bool => is_array($holiday) && isset($holiday['name'], $holiday['from'], $holiday['to'])),
            )),
        ];
    }

    /**
     * Save the calendar's holidays (Holiday Details → Add to Calendar / Update / Delete), keeping
     * the rest of the Weekends & Holidays settings; turns the holiday calendar on.
     *
     * @param  list<array<string, mixed>>  $holidays  validated
     */
    public function saveHolidays(Property $property, array $holidays): void
    {
        $fieldId = MetaField::query()->firstOrCreate(['key' => self::KEY], ['key_label' => 'Add Weekend/Holidays for your property.', 'status' => true])->id;
        $settings = app(PropertySettings::class)->json($property, self::KEY) ?? ['weekend_days' => [5, 6]];

        $settings['holidays_enabled'] = true;
        $settings['holidays'] = collect($holidays)
            ->map(fn (array $holiday): array => [
                ...$this->detail($holiday),
                'from' => $holiday['from'],
                'to' => $holiday['to'],
                'day_wise' => (bool) ($holiday['day_wise'] ?? false),
                'days' => ($holiday['day_wise'] ?? false)
                    ? array_values(array_map(fn (array $day): array => ['date' => $day['date'], ...$this->detail($day)], $holiday['days'] ?? []))
                    : [],
            ])
            ->sortBy('from')
            ->values()
            ->all();

        $property->metaFieldValues()->updateOrCreate(['meta_fields_key_id' => $fieldId], ['enabled' => true, 'data' => json_encode($settings)]);
    }

    /**
     * A chart day's marker: whether it is a weekend day, and the holiday or event on it
     * (that day's own details when the holiday is detailed day-wise).
     *
     * @param  array{weekend_days: list<int>, holidays: list<array<string, mixed>>}  $settings
     * @return array{is_weekend: bool, holiday: array{date: string, name: string, type: string, description: string}|null}
     */
    public function marker(array $settings, CarbonInterface $date): array
    {
        $day = $date->toDateString();
        $holiday = collect($settings['holidays'])->first(fn (array $holiday): bool => $holiday['from'] <= $day && $day <= $holiday['to']);
        $days = $holiday['days'] ?? [];
        $detail = $holiday && $holiday['day_wise']
            ? collect(is_array($days) ? $days : [])->firstWhere('date', $day)
            : null;

        return [
            'is_weekend' => in_array($date->dayOfWeekIso - 1, $settings['weekend_days'], true),
            'holiday' => $holiday ? ['date' => $day, ...$this->detail($detail ?? $holiday)] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{name: string, type: string, description: string}
     */
    private function detail(array $entry): array
    {
        $type = (string) ($entry['type'] ?? 'national_holiday');

        return [
            'name' => (string) ($entry['name'] ?? ''),
            'type' => self::LEGACY_TYPES[$type] ?? $type,
            'description' => (string) ($entry['description'] ?? ''),
        ];
    }
}
