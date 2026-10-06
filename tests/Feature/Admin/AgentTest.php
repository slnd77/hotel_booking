<?php

use App\Models\Agency;
use App\Models\Agent;
use App\Models\AgentRateCode;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create(['name' => 'Shant Das']);
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);

    $this->property = Property::factory()->create();
    $this->rateCode = AgentRateCode::factory()->create(['property_id' => $this->property->id, 'name' => 'Agent1']);
});

function agentPayload(array $overrides = []): array
{
    return [
        'source' => 'agent',
        'agency_name' => 'GITA COURSE',
        'agent_rate_code_id' => null,
        'contact_name' => 'LAKSHMI GOVINDA DAS',
        'phone_country_code' => '+91',
        'phone' => '97352 12358',
        'emails' => ['lgd@pamho.net', ''],
        'address' => null,
        'city' => null,
        ...$overrides,
    ];
}

it('adds an agent with a new agency and numbers the agency', function () {
    Agency::factory()->create(['property_id' => $this->property->id, 'code' => '000009']);

    $this->post(route('admin.databases.agents.store', $this->property), agentPayload(['agent_rate_code_id' => $this->rateCode->id]))
        ->assertSessionHasNoErrors();

    $agent = Agent::query()->where('contact_name', 'LAKSHMI GOVINDA DAS')->firstOrFail();

    expect($agent->agency->name)->toBe('GITA COURSE')
        ->and($agent->agency->code)->toBe('000010')
        ->and($agent->agency->agent_rate_code_id)->toBe($this->rateCode->id)
        ->and($agent->phone)->toBe('9735212358')
        ->and($agent->emails)->toBe(['lgd@pamho.net'])
        ->and($agent->created_by)->toBe($this->admin->id);
});

it('adds a contact to an existing agency matched by name', function () {
    $agency = Agency::factory()->create(['property_id' => $this->property->id, 'name' => 'GITA COURSE']);

    $this->post(route('admin.databases.agents.store', $this->property), agentPayload(['agency_name' => 'gita course', 'contact_name' => 'Second Contact']))
        ->assertSessionHasNoErrors();

    expect($agency->agents()->pluck('contact_name')->all())->toBe(['Second Contact'])
        ->and($this->property->agencies()->count())->toBe(1);
});

it('updates an agent and its agency', function () {
    $agent = Agent::factory()->for(Agency::factory()->state(['property_id' => $this->property->id, 'name' => 'GITA COURSE']))->create();

    $this->put(route('admin.databases.agents.update', [$this->property, $agent]), agentPayload(['contact_name' => 'Renamed', 'agent_rate_code_id' => $this->rateCode->id, 'city' => 'Mayapur']))
        ->assertSessionHasNoErrors();

    $agent->refresh();

    expect($agent->contact_name)->toBe('Renamed')
        ->and($agent->agency->agent_rate_code_id)->toBe($this->rateCode->id)
        ->and($agent->agency->city)->toBe('Mayapur');
});

it('rejects a missing contact, a bad e-mail and another property\'s rate code', function () {
    $otherRateCode = AgentRateCode::factory()->create();

    $this->post(route('admin.databases.agents.store', $this->property), agentPayload([
        'contact_name' => '',
        'emails' => ['nope'],
        'agent_rate_code_id' => $otherRateCode->id,
    ]))->assertSessionHasErrors(['contact_name', 'emails.0', 'agent_rate_code_id']);

    expect(Agent::query()->count())->toBe(0);
});

it('lists agents filtered by search and first letter', function () {
    Agent::factory()->for(Agency::factory()->state(['property_id' => $this->property->id, 'name' => 'GITA COURSE']))->create(['contact_name' => 'LAKSHMI']);
    Agent::factory()->for(Agency::factory()->state(['property_id' => $this->property->id, 'name' => 'CENTRAL']))->create(['contact_name' => 'JAGAT']);
    Agent::factory()->for(Agency::factory())->create(['contact_name' => 'Other property']);

    $this->get(route('admin.databases.agents.index', ['property' => $this->property->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/databases/Agents')
            ->where('agents.total', 2)
            ->where('agents.data.0.agency_name', 'CENTRAL'));

    $this->get(route('admin.databases.agents.index', ['property' => $this->property->id, 'letter' => 'G']))
        ->assertInertia(fn (Assert $page) => $page->where('agents.total', 1)->where('agents.data.0.contact_name', 'LAKSHMI'));

    $this->get(route('admin.databases.agents.index', ['property' => $this->property->id, 'q' => 'jagat']))
        ->assertInertia(fn (Assert $page) => $page->where('agents.total', 1)->where('agents.data.0.agency_name', 'CENTRAL'));
});

it('downloads the agent list as CSV', function () {
    Agent::factory()->for(Agency::factory()->state(['property_id' => $this->property->id, 'name' => 'GITA COURSE', 'agent_rate_code_id' => $this->rateCode->id]))->create(['contact_name' => 'LAKSHMI']);

    $csv = $this->get(route('admin.databases.agents.download', ['property' => $this->property->id]))->assertOk()->streamedContent();

    expect($csv)->toContain('"Agency Name","Agency Code","Agency Class",Source')
        ->and($csv)->toContain('GITA COURSE')
        ->and($csv)->toContain('"GITA COURSE",000001,Agent1,Agent,LAKSHMI');
});

it('manages agent rate codes', function () {
    $this->get(route('admin.agentSettings.edit', $this->property))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/agent-settings/Edit')->has('rateCodes', 1));

    $this->post(route('admin.agentSettings.rateCodes.store', $this->property), [
        'name' => 'Agent2', 'code' => 'AG2', 'description' => 'Travel agents', 'is_active' => true,
    ])->assertSessionHasNoErrors();

    $rateCode = AgentRateCode::query()->where('name', 'Agent2')->firstOrFail();
    expect($rateCode->code)->toBe('AG2');

    $this->post(route('admin.agentSettings.rateCodes.store', $this->property), ['name' => 'Agent2'])
        ->assertSessionHasErrors('name');

    $this->put(route('admin.agentSettings.rateCodes.update', [$this->property, $rateCode]), ['name' => 'Agent2', 'is_active' => false])
        ->assertSessionHasNoErrors();

    expect($rateCode->refresh()->is_active)->toBeFalse();
});

it('keeps a rate code that agencies use', function () {
    Agency::factory()->create(['property_id' => $this->property->id, 'agent_rate_code_id' => $this->rateCode->id]);

    $this->delete(route('admin.agentSettings.rateCodes.destroy', [$this->property, $this->rateCode]))->assertSessionHas('error');
    expect($this->rateCode->fresh())->not->toBeNull();

    $unused = AgentRateCode::factory()->create(['property_id' => $this->property->id, 'name' => 'Unused']);
    $this->delete(route('admin.agentSettings.rateCodes.destroy', [$this->property, $unused]))->assertSessionHas('success');
    expect($unused->fresh())->toBeNull();
});

function agentBookingPayload(RoomCategory $category, Room $room, array $overrides = []): array
{
    return [
        'booking_ref_id' => 'AGT-1', 'source' => 'travel_agent', 'type' => 'walk_in',
        'check_in' => now()->addDays(3)->toDateString(), 'check_out' => now()->addDays(4)->toDateString(),
        'status' => 'confirmed', 'discount' => 0, 'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        'rooms' => [['room_category_id' => $category->id, 'room_id' => $room->id, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
        ...$overrides,
    ];
}

it('saves the agent on an agent booking and requires one', function () {
    $category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    $room = Room::factory()->create(['room_category_id' => $category->id]);
    $agent = Agent::factory()->for(Agency::factory()->state(['property_id' => $this->property->id]))->create();
    $otherPropertyAgent = Agent::factory()->create();

    $this->post(route('admin.bookings.store', $this->property), agentBookingPayload($category, $room))->assertSessionHasErrors('agent_id');
    $this->post(route('admin.bookings.store', $this->property), agentBookingPayload($category, $room, ['agent_id' => $otherPropertyAgent->id]))->assertSessionHasErrors('agent_id');

    $this->post(route('admin.bookings.store', $this->property), agentBookingPayload($category, $room, ['agent_id' => $agent->id]))->assertSessionHasNoErrors();

    expect(Booking::query()->where('booking_ref_id', 'AGT-1')->sole()->agent_id)->toBe($agent->id);
});

it('drops the agent when the booking is not an agent booking', function () {
    $category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    $room = Room::factory()->create(['room_category_id' => $category->id]);
    $agent = Agent::factory()->for(Agency::factory()->state(['property_id' => $this->property->id]))->create();

    $this->post(route('admin.bookings.store', $this->property), agentBookingPayload($category, $room, ['source' => 'direct', 'agent_id' => $agent->id]))
        ->assertSessionHasNoErrors();

    expect(Booking::query()->where('booking_ref_id', 'AGT-1')->sole()->agent_id)->toBeNull();
});

it('searches agents and adds one from the booking form as JSON', function () {
    Agent::factory()->for(Agency::factory()->state(['property_id' => $this->property->id, 'name' => 'GITA COURSE']))->create(['contact_name' => 'LAKSHMI']);

    $this->getJson(route('admin.databases.agents.search', ['property' => $this->property->id, 'q' => 'gita']))
        ->assertOk()
        ->assertJsonPath('agents.0.contact_name', 'LAKSHMI')
        ->assertJsonPath('agents.0.agency_code', '000001');

    $this->postJson(route('admin.databases.agents.store', $this->property), agentPayload(['agency_name' => 'NEW AGENCY', 'contact_name' => 'New Contact', 'phone' => '9742818917']))
        ->assertCreated()
        ->assertJsonPath('agent.contact_name', 'New Contact')
        ->assertJsonPath('agent.agency_name', 'NEW AGENCY')
        ->assertJsonPath('agent.phone', '+91 9742818917');

    $this->postJson(route('admin.databases.agents.store', $this->property), agentPayload(['contact_name' => '']))
        ->assertJsonValidationErrors('contact_name');
});

it('soft deletes an agent, keeps it on past bookings, and restores it', function () {
    $agent = Agent::factory()->for(Agency::factory()->state(['property_id' => $this->property->id, 'name' => 'GITA COURSE']))->create(['contact_name' => 'Gone Soon']);
    $booking = Booking::query()->create([
        'property_id' => $this->property->id, 'booking_ref_id' => 'AGT-1', 'source' => Booking::SOURCE_TRAVEL_AGENT, 'agent_id' => $agent->id,
        'check_in' => now()->addDay()->toDateString(), 'check_out' => now()->addDays(2)->toDateString(),
    ]);

    $this->delete(route('admin.databases.agents.destroy', [$this->property, $agent]))->assertRedirect();

    expect(Agent::query()->whereKey($agent->id)->exists())->toBeFalse()
        ->and(Agent::withTrashed()->whereKey($agent->id)->exists())->toBeTrue()
        ->and($booking->fresh()->agent?->contact_name)->toBe('Gone Soon');

    // Gone from the list and the booking form's picker; listed under "Show deleted".
    $this->get(route('admin.databases.agents.index', ['property' => $this->property->id]))
        ->assertInertia(fn (Assert $page) => $page->component('admin/databases/Agents')->has('agents.data', 0));
    $this->getJson(route('admin.databases.agents.search', [$this->property, 'q' => 'Gone']))->assertOk()->assertJsonCount(0, 'agents');
    $this->get(route('admin.databases.agents.index', ['property' => $this->property->id, 'trashed' => 1]))
        ->assertInertia(fn (Assert $page) => $page->has('agents.data', 1)->where('agents.data.0.contact_name', 'Gone Soon')->whereNot('agents.data.0.deleted_at', null));

    $this->post(route('admin.databases.agents.restore', [$this->property, $agent->id]))->assertRedirect();

    expect(Agent::query()->whereKey($agent->id)->exists())->toBeTrue();
});

it('refuses to delete another property\'s agent', function () {
    $agent = Agent::factory()->for(Agency::factory()->state(['property_id' => Property::factory()->create()->id]))->create();

    $this->delete(route('admin.databases.agents.destroy', [$this->property, $agent]))->assertNotFound();
    expect(Agent::query()->whereKey($agent->id)->exists())->toBeTrue();
});
