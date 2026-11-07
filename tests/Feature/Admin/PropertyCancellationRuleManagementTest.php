<?php

use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\PropertyGroups;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    Currency::create(['code' => 'USD', 'name' => 'USD']);
    Language::create(['code' => 'en', 'name' => 'English']);

    $this->property = Property::factory()->create(['property_group_id' => PropertyGroups::factory()->create()->id]);
});

function validCancellationRulePayload(array $overrides = []): array
{
    return array_merge([
        'start_time_stamp' => '2026-01-01 00:00:00',
        'end_time_stamp' => '2026-12-31 23:59:00',
        'rules' => [
            ['day_from_checkin' => 3, 'penalty_percent' => 100],
            ['day_from_checkin' => 7, 'penalty_percent' => 75],
            ['day_from_checkin' => 15, 'penalty_percent' => 50],
            ['day_from_checkin' => 30, 'penalty_percent' => 25],
            ['day_from_checkin' => 999999, 'penalty_percent' => 0],
        ],
    ], $overrides);
}

test('a cancellation rule can be created for a property', function () {
    $response = $this->actingAs($this->admin)->post(
        "/admin/properties/{$this->property->id}/cancellation-rules",
        validCancellationRulePayload(),
    );

    $response->assertRedirect("/admin/properties/{$this->property->id}/edit?tab=cancellation-rules");
    $this->assertDatabaseHas('property_cancellation_rules', [
        'property_id' => $this->property->id,
    ]);
});

test('creating a cancellation rule fails when end date is before start date', function () {
    $response = $this->actingAs($this->admin)->post(
        "/admin/properties/{$this->property->id}/cancellation-rules",
        validCancellationRulePayload([
            'start_time_stamp' => '2026-12-31 23:59:00',
            'end_time_stamp' => '2026-01-01 00:00:00',
        ]),
    );

    $response->assertSessionHasErrors('end_time_stamp');
    $this->assertDatabaseMissing('property_cancellation_rules', ['property_id' => $this->property->id]);
});

test('creating a cancellation rule fails without at least one rule tier', function () {
    $response = $this->actingAs($this->admin)->post(
        "/admin/properties/{$this->property->id}/cancellation-rules",
        validCancellationRulePayload([
            'rules' => [],
        ]),
    );

    $response->assertSessionHasErrors('rules');
});

test('a cancellation rule can be created with a single tier', function () {
    $response = $this->actingAs($this->admin)->post(
        "/admin/properties/{$this->property->id}/cancellation-rules",
        validCancellationRulePayload([
            'rules' => [
                ['day_from_checkin' => 9999999, 'penalty_percent' => 0],
            ],
        ]),
    );

    $response->assertRedirect("/admin/properties/{$this->property->id}/edit?tab=cancellation-rules");
    $this->assertDatabaseHas('property_cancellation_rules', [
        'property_id' => $this->property->id,
    ]);
});

test('a cancellation rule can be updated', function () {
    $rule = $this->property->cancellationRules()->create(validCancellationRulePayload());

    $response = $this->actingAs($this->admin)->put(
        "/admin/properties/{$this->property->id}/cancellation-rules/{$rule->id}",
        validCancellationRulePayload(['end_time_stamp' => '2027-01-01 00:00:00']),
    );

    $response->assertRedirect("/admin/properties/{$this->property->id}/edit?tab=cancellation-rules");
    expect($rule->fresh()->end_time_stamp->toDateString())->toBe('2027-01-01');
});

test('creating a cancellation rule fails when dates overlap with an existing rule', function () {
    $this->property->cancellationRules()->create(validCancellationRulePayload([
        'start_time_stamp' => '2026-06-01 00:00:00',
        'end_time_stamp' => '2026-06-30 23:59:00',
    ]));

    $response = $this->actingAs($this->admin)->post(
        "/admin/properties/{$this->property->id}/cancellation-rules",
        validCancellationRulePayload([
            'start_time_stamp' => '2026-06-15 00:00:00',
            'end_time_stamp' => '2026-07-15 23:59:00',
        ]),
    );

    $response->assertSessionHasErrors('start_time_stamp');
});

test('updating a cancellation rule fails when dates overlap with another rule', function () {
    $rule1 = $this->property->cancellationRules()->create(validCancellationRulePayload([
        'start_time_stamp' => '2026-01-01 00:00:00',
        'end_time_stamp' => '2026-05-31 23:59:00',
    ]));

    $rule2 = $this->property->cancellationRules()->create(validCancellationRulePayload([
        'start_time_stamp' => '2026-06-01 00:00:00',
        'end_time_stamp' => '2026-12-31 23:59:00',
    ]));

    $response = $this->actingAs($this->admin)->put(
        "/admin/properties/{$this->property->id}/cancellation-rules/{$rule2->id}",
        validCancellationRulePayload([
            'start_time_stamp' => '2026-05-15 00:00:00',
            'end_time_stamp' => '2026-12-31 23:59:00',
        ]),
    );

    $response->assertSessionHasErrors('start_time_stamp');
});
