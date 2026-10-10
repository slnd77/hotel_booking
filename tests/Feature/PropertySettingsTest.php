<?php

use App\Models\Currency;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Property;
use App\Services\PropertySettings;
use App\Services\ReportSettings;
use App\Services\WeekendHolidays;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);
    $this->property = Property::factory()->create();

    $field = MetaField::query()->firstOrCreate(['key' => WeekendHolidays::KEY], ['key_label' => 'Weekends', 'status' => true]);
    $this->property->metaFieldValues()->create(['meta_fields_key_id' => $field->id, 'enabled' => true, 'data' => json_encode(['weekend_days' => [6]])]);
    app(PropertySettings::class)->forget();
});

/**
 * @return int queries run by the callback
 */
function countQueries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    DB::disableQueryLog();

    return count(DB::getQueryLog());
}

it('reads a property\'s settings once per request', function () {
    $first = countQueries(fn () => [app(WeekendHolidays::class)->get($this->property), app(ReportSettings::class)->get($this->property)]);
    $again = countQueries(fn () => [app(WeekendHolidays::class)->get($this->property), app(ReportSettings::class)->get($this->property), app(WeekendHolidays::class)->get($this->property)]);

    // Meta field ids, then all of the property's values: two queries for any number of settings.
    expect($first)->toBe(2)
        ->and($again)->toBe(0)
        ->and(app(WeekendHolidays::class)->get($this->property)['weekend_days'])->toBe([6]);
});

it('reads a setting fresh after it is saved', function () {
    expect(app(WeekendHolidays::class)->get($this->property)['weekend_days'])->toBe([6]);

    $this->property->metaFieldValues()->firstWhere('meta_fields_key_id', MetaField::query()->where('key', WeekendHolidays::KEY)->value('id'))
        ->update(['data' => json_encode(['weekend_days' => [5, 6]])]);

    expect(app(WeekendHolidays::class)->get($this->property)['weekend_days'])->toBe([5, 6]);
});

it('starts every request with nothing cached', function () {
    $first = app(PropertySettings::class);
    app()->forgetScopedInstances();

    expect(app(PropertySettings::class))->not->toBe($first);
});
