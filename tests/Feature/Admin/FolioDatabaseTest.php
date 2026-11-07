<?php

use App\Enums\BookingStatus;
use App\Enums\FolioStatus;
use App\Enums\TransactionType;
use App\Models\Booking;
use App\Models\CompanyProfile;
use App\Models\Currency;
use App\Models\Folio;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\SubFolio;
use App\Models\Transaction;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->property = Property::factory()->create();
});

function folioBooking(Property $property, string $ref): Booking
{
    return $property->bookings()->create([
        'booking_ref_id' => $ref, 'source' => Booking::SOURCE_DIRECT, 'type' => 'walk_in', 'status' => BookingStatus::Confirmed,
        'check_in' => now()->addDay()->toDateString(), 'check_out' => now()->addDays(2)->toDateString(),
        'room_total' => 1000, 'discount' => 0, 'taxes' => 0, 'total' => 1000, 'outstanding' => 0,
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
    ]);
}

/**
 * A folio with one window and the given debits and credits.
 *
 * @param  list<float>  $debits
 * @param  list<float>  $credits
 */
function folioWithLedger(string $ownerType, int $ownerId, array $debits, array $credits, FolioStatus $status = FolioStatus::Open): Folio
{
    $folio = Folio::factory()->create(['owner_type' => $ownerType, 'owner_id' => $ownerId, 'status' => $status]);
    $window = SubFolio::factory()->create(['folio_id' => $folio->id]);

    foreach ([TransactionType::Debit->value => $debits, TransactionType::Credit->value => $credits] as $type => $amounts) {
        foreach ($amounts as $amount) {
            Transaction::factory()->create(['sub_folio_id' => $window->id, 'transaction_type' => $type, 'amount' => $amount]);
        }
    }

    return $folio;
}

/**
 * @return array<int, array<string, mixed>> the listed folios by id
 */
function listedFolios(object $test, array $query = []): array
{
    $page = $test->actingAs($test->admin)
        ->get(route('admin.databases.folios.index', ['property' => $test->property->id, ...$query]))
        ->assertOk()
        ->viewData('page');

    return collect($page['props']['folios']['data'])->keyBy('id')->all();
}

it('lists the property folios with type, status, currency, owner and balance', function () {
    $booking = folioBooking($this->property, 'FOL-1');
    $category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    $stay = $booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => Room::factory()->create(['room_category_id' => $category->id, 'name' => '101'])->id, 'meal_plan' => 'EP', 'adults' => 2, 'rate_per_night' => 1000]);
    $company = CompanyProfile::query()->create(['property_id' => $this->property->id, 'name' => 'Acme Travels', 'tax_number' => '27AAAAA0000A1Z5']);

    $room = folioWithLedger('sub_booking', $stay->id, [1000, 180], [500]);
    $master = folioWithLedger('booking', $booking->id, [200], [200], FolioStatus::Settled);
    $companyFolio = folioWithLedger('company_profile', $company->id, [3000], [3500]);

    $otherBooking = folioBooking(Property::factory()->create(), 'FOL-OTHER');
    $elsewhere = folioWithLedger('booking', $otherBooking->id, [50], []);

    $rows = listedFolios($this);

    expect($rows)->not->toHaveKey($elsewhere->id)
        ->and($rows[$room->id])->toMatchArray([
            'type_label' => 'Room Folio', 'status' => 'open', 'currency' => 'INR', 'booking_ref_id' => 'FOL-1', 'room' => '101',
            'windows' => 1, 'charges' => 1180.0, 'payments' => 500.0, 'balance' => 680.0,
        ])
        ->and($rows[$master->id])->toMatchArray(['type_label' => 'Master Folio', 'status' => 'settled', 'balance' => 0.0])
        ->and($rows[$companyFolio->id])->toMatchArray(['type_label' => 'Company Folio', 'owner_name' => 'Acme Travels', 'owner_detail' => '27AAAAA0000A1Z5', 'balance' => -500.0]);
});

it('filters folios by type, status, balance and search', function () {
    $booking = folioBooking($this->property, 'FOL-FIND');
    $company = CompanyProfile::query()->create(['property_id' => $this->property->id, 'name' => 'Globex']);
    $due = folioWithLedger('booking', $booking->id, [100], []);
    $settled = folioWithLedger('company_profile', $company->id, [100], [100], FolioStatus::Settled);

    expect(listedFolios($this, ['type' => 'company_profile']))->toHaveKey($settled->id)->not->toHaveKey($due->id)
        ->and(listedFolios($this, ['status' => 'settled']))->toHaveKey($settled->id)->not->toHaveKey($due->id)
        ->and(listedFolios($this, ['balance' => 'due']))->toHaveKey($due->id)->not->toHaveKey($settled->id)
        ->and(listedFolios($this, ['balance' => 'zero']))->toHaveKey($settled->id)->not->toHaveKey($due->id)
        ->and(listedFolios($this, ['q' => 'FOL-FIND']))->toHaveKey($due->id)->not->toHaveKey($settled->id)
        ->and(listedFolios($this, ['q' => 'Globex']))->toHaveKey($settled->id)->not->toHaveKey($due->id);
});

it('downloads the folio list as csv', function () {
    $booking = folioBooking($this->property, 'FOL-CSV');
    folioWithLedger('booking', $booking->id, [250], [100]);

    $response = $this->actingAs($this->admin)->get(route('admin.databases.folios.download', ['property' => $this->property->id]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->getContent())->toContain('Type,Status,Currency,Owner')
        ->toContain('"Master Folio",open,INR')
        ->toContain('FOL-CSV');
});
