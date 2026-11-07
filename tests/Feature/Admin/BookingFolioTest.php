<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\CompanyProfile;
use App\Models\Currency;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\SubFolio;
use App\Models\User;
use App\Services\PaymentDistributionService;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $this->property->id]);

    $this->booking = $this->property->bookings()->create([
        'booking_ref_id' => 'FOLIO-1', 'source' => 'direct', 'type' => 'walk_in', 'status' => BookingStatus::Confirmed,
        'check_in' => now()->addDay()->toDateString(), 'check_out' => now()->addDays(3)->toDateString(),
        'room_total' => 2000, 'discount' => 0, 'taxes' => 0, 'total' => 2000, 'outstanding' => 2000,
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
    ]);
    $this->booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => Room::factory()->create(['room_category_id' => $category->id])->id, 'meal_plan' => 'EP', 'adults' => 1, 'rate_per_night' => 1000]);
});

function masterFolio(Booking $booking): Folio
{
    return $booking->folios()->oldest('id')->firstOrFail();
}

test('a new folio can be opened on a booking beside its master folio', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.bookings.folios.store', [$this->property, $this->booking]), ['window_name' => 'Company'])
        ->assertSessionHas('success');

    $folios = $this->booking->folios()->oldest('id')->with('subFolios')->get();

    expect($folios)->toHaveCount(2)
        ->and($folios[1]->subFolios->sole()->window_name)->toBe('Company')
        // The master folio stays the default: the room bill is still netted on it.
        ->and($this->booking->fresh()->outstandingBalance())->toEqual(2000.0);

    $labels = collect(app(PaymentDistributionService::class)->folioWindowsFor($this->booking->fresh()))->pluck('label');
    expect($labels)->toContain('Master folio', "Folio #{$folios[1]->id}");
});

test('windows are added to a folio with the next letter or a given name', function () {
    $master = masterFolio($this->booking);

    $this->actingAs($this->admin)->post(route('admin.bookings.folios.windows.store', [$this->property, $this->booking, $master]))->assertSessionHas('success');
    $this->actingAs($this->admin)->post(route('admin.bookings.folios.windows.store', [$this->property, $this->booking, $master]), ['window_name' => 'Extras'])->assertSessionHas('success');

    expect($master->subFolios()->orderBy('window_number')->pluck('window_name')->all())->toBe(['Window A', 'Window B', 'Extras']);
});

test('a balance can be moved to an added window and paid there', function () {
    $master = masterFolio($this->booking);
    $this->actingAs($this->admin)->post(route('admin.bookings.folios.store', [$this->property, $this->booking]))->assertSessionHas('success');
    $from = SubFolio::query()->whereIn('folio_id', $this->booking->allFoliosQuery()->select('id'))->get()->first(fn (SubFolio $window): bool => $window->balance() > 0);
    $extra = SubFolio::query()->where('folio_id', '!=', $master->id)->whereHas('folio', fn ($query) => $query->whereMorphedTo('owner', $this->booking))->sole();

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.folioTransfers.store', [$this->property, $this->booking]), ['from_sub_folio_id' => $from->id, 'to_sub_folio_id' => $extra->id, 'amount' => 500])
        ->assertSessionHas('success');

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.splitPayments.store', [$this->property, $this->booking]), ['payment_mode' => 'Cash', 'distributions' => [['sub_folio_id' => $extra->id, 'amount' => 500]]])
        ->assertSessionHas('success');

    expect($extra->balance())->toEqual(0.0)
        ->and($this->booking->fresh()->outstandingBalance())->toEqual(1500.0);
});

test('a folio of another booking cannot get a window, nor can a closed folio', function () {
    $other = $this->property->bookings()->create([
        'booking_ref_id' => 'FOLIO-2', 'source' => 'direct', 'type' => 'walk_in', 'status' => BookingStatus::Confirmed,
        'check_in' => now()->addDay()->toDateString(), 'check_out' => now()->addDays(2)->toDateString(),
        'room_total' => 0, 'discount' => 0, 'taxes' => 0, 'total' => 0, 'outstanding' => 0,
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
    ]);

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.folios.windows.store', [$this->property, $other, masterFolio($this->booking)]))
        ->assertNotFound();

    $master = masterFolio($this->booking);
    $master->update(['status' => 'settled']);

    // A settled folio reopens for the new window.
    $this->actingAs($this->admin)
        ->post(route('admin.bookings.folios.windows.store', [$this->property, $this->booking, $master]))
        ->assertSessionHasNoErrors();

    expect($master->subFolios()->count())->toBe(2)
        ->and($master->fresh()->status->value)->toBe('open');
});

test('adding folios needs the billing preference permission', function () {
    $clerk = User::factory()->create();

    $this->actingAs($clerk)
        ->post(route('admin.bookings.folios.store', [$this->property, $this->booking]))
        ->assertForbidden();
});

test('a window can be billed to one of the booking\'s guests or to a new company', function () {
    $guest = Guest::factory()->create(['first_name' => 'Asha', 'last_name' => 'Rao']);
    $this->booking->guests()->attach($guest);
    $master = masterFolio($this->booking);

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.folios.windows.store', [$this->property, $this->booking, $master]), ['payer_type' => 'guest', 'payer_id' => $guest->id])
        ->assertSessionHas('success');

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.folios.store', [$this->property, $this->booking]), ['payer_type' => 'company_profile', 'company_name' => 'Acme Travels'])
        ->assertSessionHas('success');

    $company = CompanyProfile::query()->where('name', 'Acme Travels')->sole();
    $guestWindow = $master->subFolios()->where('window_number', 2)->sole();
    $companyWindow = SubFolio::query()->where('payer_type', 'company_profile')->sole();

    expect($guestWindow->payer->is($guest))->toBeTrue()
        ->and($company->property_id)->toBe($this->property->id)
        ->and($companyWindow->payer_id)->toBe($company->id);

    // Moving Window A to the company, then back to the folio owner.
    $windowA = $master->subFolios()->where('window_number', 1)->sole();
    $this->actingAs($this->admin)
        ->patch(route('admin.bookings.folios.windows.update', [$this->property, $this->booking, $windowA]), ['payer_type' => 'company_profile', 'payer_id' => $company->id])
        ->assertSessionHas('success');
    expect($windowA->fresh()->payer_id)->toBe($company->id);

    $this->actingAs($this->admin)
        ->patch(route('admin.bookings.folios.windows.update', [$this->property, $this->booking, $windowA]), ['window_name' => 'Room & tax'])
        ->assertSessionHas('success');
    expect($windowA->fresh())->payer_id->toBeNull()->window_name->toBe('Room & tax');
});

test('a guest of another booking or a company of another property cannot pay a window', function () {
    $stranger = Guest::factory()->create();
    $otherCompany = CompanyProfile::factory()->create(['property_id' => Property::factory()->create()->id]);
    $master = masterFolio($this->booking);

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.folios.windows.store', [$this->property, $this->booking, $master]), ['payer_type' => 'guest', 'payer_id' => $stranger->id])
        ->assertSessionHasErrors('payer_id');

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.folios.windows.store', [$this->property, $this->booking, $master]), ['payer_type' => 'company_profile', 'payer_id' => $otherCompany->id])
        ->assertSessionHasErrors('payer_id');

    expect($master->subFolios()->count())->toBe(1);
});
