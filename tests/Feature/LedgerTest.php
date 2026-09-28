<?php

use App\Enums\ChargeCategory;
use App\Enums\FolioStatus;
use App\Models\Booking;
use App\Models\ChargeCode;
use App\Models\CompanyProfile;
use App\Models\Currency;
use App\Models\Folio;
use App\Models\InventoryUnit;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\RoutingRule;
use App\Models\SubBooking;
use App\Services\Ledger;

beforeEach(function () {
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->ledger = app(Ledger::class);

    $property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $property->id]);

    $this->booking = Booking::query()->create([
        'property_id' => $property->id,
        'booking_ref_id' => 'GRP-1001',
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(3)->toDateString(),
    ]);

    // Three rooms under one confirmation number.
    $this->rooms = collect(range(1, 3))->map(fn () => SubBooking::factory()->create([
        'booking_id' => $this->booking->id,
        'inventory_unit_id' => InventoryUnit::factory()->create(['room_id' => Room::factory()->create(['room_category_id' => $category->id])->id])->id,
    ]));

    $this->roomCharge = ChargeCode::factory()->category(ChargeCategory::RoomAndTax)->create(['code' => 'ROOM-NIGHT']);
    $this->foodCharge = ChargeCode::factory()->category(ChargeCategory::FoodAndBeverage)->create(['code' => 'FNB']);
    $this->payment = ChargeCode::factory()->category(ChargeCategory::Payment)->create(['code' => 'PAY-CARD']);

    // Rooms 1 and 2 send their room & tax to the parent's master folio.
    $this->masterWindow = $this->ledger->defaultWindowFor($this->booking);

    foreach ($this->rooms->take(2) as $room) {
        RoutingRule::factory()->create(['source_sub_booking_id' => $room->id, 'target_sub_folio_id' => $this->masterWindow->id, 'charge_category' => ChargeCategory::RoomAndTax]);
    }
});

test('the parent booking balance covers every room, counting routed charges once', function () {
    foreach ($this->rooms as $room) {
        $this->ledger->postCharge($room, $this->roomCharge, 6000, 'Room & tax, 2 nights');
    }

    // Food isn't routed: it stays on room 1's own folio.
    $this->ledger->postCharge($this->rooms[0], $this->foodCharge, 500, 'Dinner');
    $this->ledger->postPayment($this->masterWindow, $this->payment, 5000, description: 'Advance');

    expect($this->masterWindow->folio->balance())->toBe(7000.0) // rooms 1 + 2 room & tax, less the advance
        ->and($this->rooms[0]->folio->balance())->toBe(500.0)
        ->and($this->rooms[1]->folio->balance())->toBe(0.0) // everything it generated was routed away
        ->and($this->rooms[2]->folio->balance())->toBe(6000.0)
        ->and($this->booking->outstandingBalance())->toBe(13500.0);

    // The room that generated a routed charge is still traceable.
    expect($this->rooms[0]->generatedTransactions()->sum('amount'))->toEqual(6500);
});

test('charges routed to a company folio are the company\'s debt, not the booking\'s', function () {
    $company = CompanyProfile::factory()->create();
    $companyWindow = $this->ledger->defaultWindowFor($company);

    RoutingRule::factory()->create(['source_sub_booking_id' => $this->rooms[2]->id, 'target_sub_folio_id' => $companyWindow->id, 'charge_category' => ChargeCategory::AllCharges]);

    $this->ledger->postCharge($this->rooms[2], $this->roomCharge, 6000);
    $this->ledger->postCharge($this->rooms[2], $this->foodCharge, 800);

    expect($companyWindow->balance())->toBe(6800.0)
        ->and($this->booking->outstandingBalance())->toBe(0.0);
});

test('a charge posted to a settled folio reopens it', function () {
    Folio::query()->whereKey($this->masterWindow->folio_id)->update(['status' => FolioStatus::Settled]);

    $this->ledger->postCharge($this->rooms[0], $this->roomCharge, 100);

    expect(Folio::query()->find($this->masterWindow->folio_id)->status)->toBe(FolioStatus::Open);
});
