<?php

use App\Enums\BookingStatus;
use App\Enums\OutletBillStatus;
use App\Enums\OutletType;
use App\Mail\OutletBillMail;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
use App\Models\NightAudit;
use App\Models\Outlet;
use App\Models\OutletBill;
use App\Models\OutletItem;
use App\Models\OutletPricePlan;
use App\Models\OutletTaxRate;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\ApiScopes;
use App\Services\NightAudits;
use App\Services\NightAuditSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create();
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id]);

    $this->outlet = Outlet::factory()->create(['property_id' => $this->property->id, 'name' => 'Cafe', 'type' => OutletType::FoodAndBeverages, 'printer_setting' => 'thermal_80']);
    $taxRate = OutletTaxRate::factory()->create(['outlet_id' => $this->outlet->id, 'rate' => 5]);
    $this->coffee = OutletItem::factory()->create(['outlet_id' => $this->outlet->id, 'name' => 'Coffee', 'outlet_tax_rate_id' => $taxRate->id]);
    $this->cake = OutletItem::factory()->create(['outlet_id' => $this->outlet->id, 'name' => 'Cake']);
    $plan = OutletPricePlan::factory()->create(['outlet_id' => $this->outlet->id, 'start_date' => now()->subMonths(6)->toDateString(), 'end_date' => now()->addMonths(6)->toDateString()]);
    $plan->prices()->createMany([['outlet_item_id' => $this->coffee->id, 'price' => 100], ['outlet_item_id' => $this->cake->id, 'price' => 250]]);
});

/**
 * Opens a bill for table T4, by default 2 coffees (100 + 5% tax each) and a cake (250): 460.
 *
 * @param  array<int, int>|null  $quantities  item id => quantity
 */
function tableBill($test, ?array $quantities = null): OutletBill
{
    $quantities ??= [$test->coffee->id => 2, $test->cake->id => 1];

    $test->actingAs($test->admin)
        ->post(route('admin.pos.bills.store', [$test->property, $test->outlet]), [
            'table_number' => 'T4',
            'items' => collect($quantities)->map(fn (int $quantity, int $itemId): array => ['outlet_item_id' => $itemId, 'quantity' => $quantity])->values()->all(),
        ])
        ->assertSessionHasNoErrors();

    return OutletBill::query()->latest('id')->firstOrFail();
}

function guestInHouse(Property $property, RoomCategory $category): Booking
{
    return DB::transaction(function () use ($property, $category): Booking {
        $booking = Booking::create([
            'property_id' => $property->id, 'booking_ref_id' => 'POSROOM1', 'status' => BookingStatus::CheckedIn,
            'check_in' => now()->toDateString(), 'check_out' => now()->addDays(2)->toDateString(),
            'room_total' => 2000, 'total' => 2000, 'outstanding' => 2000, 'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        ]);
        $booking->rooms()->create(['room_category_id' => $category->id, 'meal_plan' => 'EP', 'adults' => 2, 'rate_per_night' => 1000]);

        return $booking;
    })->refresh();
}

/**
 * The text an ESC/POS print puts on paper, line by line (its commands taken out).
 *
 * @return list<string>
 */
function printedLines(string $bytes): array
{
    $text = preg_replace(['/\x1B@/', '/\x1B[taEd!]./s', '/\x1D!./s', '/\x1DV../s'], '', $bytes);

    return array_values(array_filter(explode("\n", $text), fn (string $line): bool => $line !== ''));
}

test('kitchen tickets carry only what changed on the bill, and taking off sent items needs permission', function () {
    $bill = tableBill($this);

    $this->actingAs($this->admin)->post(route('admin.pos.bills.kots.store', [$this->property, $bill]), ['notes' => 'No sugar'])->assertSessionHasNoErrors();

    $first = $bill->kots()->sole();
    expect($first->kot_number)->toBe('1')
        ->and($first->notes)->toBe('No sugar')
        ->and($first->business_date->toDateString())->toBe($bill->business_date->toDateString())
        ->and($first->items->pluck('quantity', 'name')->all())->toBe(['Coffee' => 2, 'Cake' => 1]);

    // Nothing changed since.
    $this->actingAs($this->admin)->post(route('admin.pos.bills.kots.store', [$this->property, $bill]))->assertSessionHasErrors('bill');

    // The cake is with the kitchen: taking it off needs "Can Reduce Quantity Of Item".
    $waiter = User::factory()->create();
    $waiter->assignRole(Role::findOrCreate('Waiter', 'web'));
    $waiter->givePermissionTo(Permission::findOrCreate('pos.post_transaction', 'web'));

    $this->actingAs($waiter)
        ->put(route('admin.pos.bills.update', [$this->property, $bill]), ['items' => [['outlet_item_id' => $this->coffee->id, 'quantity' => 3]]])
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->put(route('admin.pos.bills.update', [$this->property, $bill]), ['table_number' => 'T4', 'items' => [['outlet_item_id' => $this->coffee->id, 'quantity' => 3]]])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('admin.pos.bills.kots.store', [$this->property, $bill]))->assertSessionHasNoErrors();

    expect($bill->kots()->get()->last()->items->pluck('quantity', 'name')->all())->toBe(['Coffee' => 1, 'Cake' => -1]);

    // Voiding the open bill cancels everything the kitchen has.
    $this->actingAs($this->admin)->post(route('admin.pos.bills.void', [$this->property, $bill]), ['reason' => 'Guest left'])->assertSessionHasNoErrors();

    $cancellation = $bill->kots()->get()->last();
    expect($bill->kots()->count())->toBe(3)
        ->and($cancellation->isCancellation())->toBeTrue()
        ->and($cancellation->items->pluck('quantity', 'name')->all())->toBe(['Coffee' => -3]);
});

test('bills and kitchen tickets print as pages for A4, 80mm and 58mm paper, and as ESC/POS', function () {
    $bill = tableBill($this);
    $this->actingAs($this->admin)->post(route('admin.pos.bills.kots.store', [$this->property, $bill]));
    $this->actingAs($this->admin)->post(route('admin.pos.bills.settle', [$this->property, $bill]), ['payment_mode' => 'UPI'])->assertSessionHasNoErrors();

    $print = fn (array $query) => $this->actingAs($this->admin)->get(route('admin.pos.bills.print', [$this->property, $bill, ...$query]))->assertOk();

    $print(['paper' => 'a4'])->assertSee('@page { size: A4', false)->assertSee('Bill #1')->assertSee('Tax @ 5%')->assertSee('Paid by UPI');
    $print([])->assertSee('size: 80mm auto', false)->assertSee('>Rate<', false);
    $print(['paper' => 'thermal_58'])->assertSee('size: 58mm auto', false)->assertDontSee('>Rate<', false);

    $narrow = $print(['paper' => 'thermal_58', 'format' => 'escpos'])->assertHeader('Content-Type', 'application/octet-stream')->getContent();
    $wide = $print(['format' => 'escpos'])->getContent();

    expect($narrow)->toStartWith("\x1B@")
        ->and($narrow)->toContain("\x1DV")
        ->and(max(array_map('strlen', printedLines($narrow))))->toBeLessThanOrEqual(32)
        ->and(implode("\n", printedLines($narrow)))->toContain('Coffee')->toContain('Total (USD)')
        ->and(max(array_map('strlen', printedLines($wide))))->toBeLessThanOrEqual(48)
        ->and(implode("\n", printedLines($wide)))->toContain('Rate');

    $kot = $bill->kots()->sole();
    $this->actingAs($this->admin)->get(route('admin.pos.kots.print', [$this->property, $kot]))->assertOk()->assertSee('KOT #1')->assertSee('Table: T4');

    expect($kot->fresh()->printed_at)->not->toBeNull();
});

test('network printers only take a raw-print port, never a local address, and a failed kitchen print keeps the ticket', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.outlets.update', [$this->property, $this->outlet]), ['name' => 'Cafe', 'type' => 'Food & Beverages', 'printer_setting' => 'thermal_80', 'printer_host' => '192.168.1.50', 'printer_port' => 80])
        ->assertSessionHasErrors('printer_port');

    $bill = tableBill($this);

    $this->actingAs($this->admin)->post(route('admin.pos.bills.sendToPrinter', [$this->property, $bill]))->assertSessionHasErrors('printer');

    $this->outlet->update(['printer_host' => '127.0.0.1', 'printer_port' => 9100, 'kot_printer_host' => '169.254.169.254', 'kot_printer_port' => 9100]);

    $this->actingAs($this->admin)->post(route('admin.pos.bills.sendToPrinter', [$this->property, $bill]))
        ->assertSessionHasErrors(['printer' => "The printer address 127.0.0.1:9100 isn't allowed."]);

    $this->actingAs($this->admin)->post(route('admin.pos.bills.kots.store', [$this->property, $bill]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('error');

    expect($bill->kots()->sole()->printed_at)->toBeNull();
});

test('with the POS roll-over flag on, bills take the open business date and the night audit waits for them', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 06:00:00', 'UTC'));
    $this->property->update(['timezone' => 'Asia/Kolkata']);
    $settings = app(NightAuditSettings::class);
    $settings->save($this->property, ['pos_rollover' => true, 'current_rollover_date' => '2026-10-01', 'freeze_past_dated_data' => true]);

    $bill = tableBill($this);
    expect($bill->business_date->toDateString())->toBe('2026-10-01');

    $audit = app(NightAudits::class)->run($this->property, CarbonImmutable::parse('2026-10-01'));
    expect($audit->status)->toBe(NightAudit::FAILED)
        ->and($audit->error)->toContain('Cafe #1');

    $this->actingAs($this->admin)->post(route('admin.pos.bills.settle', [$this->property, $bill]), ['payment_mode' => 'Cash'])->assertSessionHasNoErrors();

    $audit = app(NightAudits::class)->run($this->property, CarbonImmutable::parse('2026-10-01'));
    expect($audit->status)->toBe(NightAudit::COMPLETED)
        ->and($audit->summary['report']['pos'])->toMatchArray(['bills' => 1, 'total' => 460, 'collected' => 460, 'charged_to_rooms' => 0]);

    // The day is closed and frozen: its bills can't be voided now.
    $this->actingAs($this->admin)->post(route('admin.pos.bills.void', [$this->property, $bill]), ['reason' => 'Late'])->assertSessionHas('error');
    expect($bill->fresh()->status)->toBe(OutletBillStatus::Settled);

    // POS has rolled over to the next date.
    expect(tableBill($this)->business_date->toDateString())->toBe('2026-10-02');
});

test('POS reports sum sales by outlet, settlement and item, apart from open and void bills', function () {
    $booking = guestInHouse($this->property, $this->category);

    $this->actingAs($this->admin)->post(route('admin.pos.bills.settle', [$this->property, tableBill($this)]), ['payment_mode' => 'UPI']);
    $this->actingAs($this->admin)->post(route('admin.pos.bills.postToRoom', [$this->property, tableBill($this)]), [
        'sub_booking_id' => $booking->rooms()->sole()->id,
        'payment' => ['payment_mode' => 'Cash', 'amount' => 160],
    ])->assertSessionHasNoErrors();
    $open = tableBill($this, [$this->coffee->id => 1]);
    $this->actingAs($this->admin)->post(route('admin.pos.bills.void', [$this->property, tableBill($this, [$this->cake->id => 1])]), ['reason' => 'Mistake']);

    $this->actingAs($this->admin)
        ->get(route('admin.pos.report', $this->property))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/pos/Report')
            ->where('summary.totals.bills', 2)
            ->where('summary.totals.net_sales', 900)
            ->where('summary.totals.tax_total', 20)
            ->where('summary.totals.total', 920)
            ->where('summary.totals.charged_to_rooms', 300)
            ->where('summary.totals.collected', 620)
            ->where('summary.totals.open_bills', 1)
            ->where('summary.totals.open_amount', 105)
            ->where('summary.totals.void_bills', 1)
            ->where('summary.settlements', [
                ['label' => 'Charged to rooms', 'count' => 1, 'amount' => 300],
                ['label' => 'Cash', 'count' => 1, 'amount' => 160],
                ['label' => 'UPI', 'count' => 1, 'amount' => 460],
            ])
            ->where('summary.items', [
                ['outlet' => 'Cafe', 'name' => 'Cake', 'quantity' => 2, 'amount' => 500],
                ['outlet' => 'Cafe', 'name' => 'Coffee', 'quantity' => 4, 'amount' => 400],
            ])
            ->has('summary.bills', 4));

    $today = $this->property->today();
    $this->actingAs($this->admin)
        ->get(route('admin.reports.download', [$this->property, 'pos_sales_report'])."?date={$today}&end_date={$today}")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    // The same over the API, with kitchen tickets and ESC/POS prints.
    app('auth')->forgetGuards();
    $key = $this->admin->createToken(ApiScopes::KEY_PREFIX.'Till', ['pos:read', 'pos:write'])->plainTextToken;

    $this->withToken($key)->getJson(route('api.v1.pos.summary', $this->property))->assertOk()->assertJsonPath('data.totals.total', 920);
    $kotId = $this->withToken($key)->postJson(route('api.v1.outletBills.kots.store', [$this->property, $open]))
        ->assertCreated()
        ->assertJsonPath('data.kots.0.items.0.name', 'Coffee')
        ->assertJsonPath('printed', false)
        ->json('kot_id');
    $this->withToken($key)->get(route('api.v1.kots.print', [$this->property, $kotId, 'format' => 'escpos']))->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
    $this->withToken($key)->get(route('api.v1.outletBills.print', [$this->property, $open, 'format' => 'escpos', 'paper' => 'thermal_58']))->assertOk();
});

test('an outlet menu QR code opens its public menu with today\'s prices, until the code is renewed', function () {
    OutletItem::factory()->create(['outlet_id' => $this->outlet->id, 'name' => 'Unpriced Tea']);

    $response = $this->actingAs($this->admin)
        ->get(route('admin.outlets.qr', [$this->property, $this->outlet, 'table' => 'T4']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml');

    $menuUrl = $response->headers->get('X-Menu-Url');
    expect($response->getContent())->toContain('<svg')
        ->and($menuUrl)->toBe(route('outlet-menu.show', [$this->outlet->fresh()->menu_token, 'table' => 'T4']));

    $this->get($menuUrl)->assertOk()->assertSee('Cafe')->assertSee('Table T4')->assertSee('Coffee')->assertSee('100.00')->assertSee('+ 5% tax')->assertDontSee('Unpriced Tea');

    $this->actingAs($this->admin)->post(route('admin.outlets.renewQr', [$this->property, $this->outlet]))->assertSessionHas('success');
    $this->get($menuUrl)->assertNotFound();

    $this->outlet->refresh()->update(['status' => false]);
    $this->get(route('outlet-menu.show', $this->outlet->menu_token))->assertNotFound();
});

test('a cash sale opens the cash drawer with its receipt, and the drawer opens without a sale only on record', function () {
    $this->outlet->update(['cash_drawer' => true]);
    $cash = tableBill($this);
    $this->actingAs($this->admin)->post(route('admin.pos.bills.settle', [$this->property, $cash]), ['payment_mode' => 'Cash'])->assertSessionHasNoErrors();
    $upi = tableBill($this);
    $this->actingAs($this->admin)->post(route('admin.pos.bills.settle', [$this->property, $upi]), ['payment_mode' => 'UPI']);

    $receipt = fn (OutletBill $bill, array $query = ['drawer' => 1]) => $this->actingAs($this->admin)->get(route('admin.pos.bills.print', [$this->property, $bill, 'format' => 'escpos', ...$query]))->getContent();
    $kick = "\x1Bp\x00";

    expect($receipt($cash))->toContain($kick)
        ->and($receipt($cash, []))->not->toContain($kick)
        ->and($receipt($upi))->not->toContain($kick);

    $this->outlet->update(['cash_drawer' => false]);
    expect($receipt($cash))->not->toContain($kick);

    // No drawer: it can't be opened.
    $this->actingAs($this->admin)->get(route('admin.pos.drawer.escpos', [$this->property, $this->outlet]))->assertSessionHasErrors('printer');

    $this->outlet->update(['cash_drawer' => true]);
    expect($this->actingAs($this->admin)->get(route('admin.pos.drawer.escpos', [$this->property, $this->outlet]))->assertOk()->getContent())->toContain($kick)
        ->and(ActivityLog::query()->where('action', 'POS: cash drawer of Cafe opened without a sale')->exists())->toBeTrue();

    // On the network printer: none set, then one the server may not reach.
    $this->actingAs($this->admin)->post(route('admin.pos.drawer.open', [$this->property, $this->outlet]))->assertSessionHasErrors('printer');

    $this->outlet->update(['printer_host' => '127.0.0.1', 'printer_port' => 9100]);
    $bill = tableBill($this);
    $this->actingAs($this->admin)->post(route('admin.pos.bills.settle', [$this->property, $bill]), ['payment_mode' => 'Cash'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('error');

    expect($bill->fresh()->status)->toBe(OutletBillStatus::Settled);
});

test('a bill posted to a room opens from the folio, downloads as a PDF, is e-mailed, and is on the guest bill summary', function () {
    Pdf::fake();
    Mail::fake();
    $booking = guestInHouse($this->property, $this->category);
    $bill = tableBill($this);
    $this->actingAs($this->admin)->post(route('admin.pos.bills.postToRoom', [$this->property, $bill]), ['sub_booking_id' => $booking->rooms()->sole()->id])->assertSessionHasNoErrors();

    // The folio entry links to the bill.
    $this->actingAs($this->admin)->get(route('admin.bookings.show', [$this->property, $booking]))
        ->assertInertia(fn ($page) => $page->where('folios', fn ($folios): bool => collect($folios)->flatMap(fn ($folio) => collect($folio['windows'])->flatMap(fn ($window) => $window['entries']))->contains('outlet_bill_id', $bill->id)));

    $this->actingAs($this->admin)->getJson(route('admin.pos.bills.show', [$this->property, $bill]))
        ->assertOk()
        ->assertJsonPath('status', 'posted')
        ->assertJsonPath('data.bill_number', '1')
        ->assertJsonPath('data.total', 460);

    $this->actingAs($this->admin)->get(route('admin.pos.bills.pdf', [$this->property, $bill]))->assertOk();
    Pdf::assertRespondedWithPdf(fn (PdfBuilder $pdf): bool => $pdf->isDownload() && $pdf->downloadName === 'cafe-bill-1.pdf' && $pdf->contains('Coffee') && ! $pdf->contains('Close</button>'));

    $this->actingAs($this->admin)->post(route('admin.pos.bills.email', [$this->property, $bill]), ['email' => 'guest@example.com'])->assertSessionHas('success');
    Mail::assertQueued(OutletBillMail::class, fn (OutletBillMail $mail): bool => $mail->hasTo('guest@example.com') && $mail->bill->is($bill));

    // Front desk with "print bill" but no POS access can still open it.
    $desk = User::factory()->create();
    $desk->assignRole(Role::findOrCreate('Desk', 'web'));
    $desk->givePermissionTo(Permission::findOrCreate('reservation.print_bill', 'web'));
    $this->actingAs($desk)->getJson(route('admin.pos.bills.show', [$this->property, $bill]))->assertOk();
    $this->actingAs($desk)->post(route('admin.pos.bills.void', [$this->property, $bill]), ['reason' => 'x'])->assertForbidden();

    $this->actingAs($this->admin)->get(route('admin.bookings.print', [$this->property, $booking, 'bill-summary']).'?preview=1')
        ->assertOk()
        ->assertSee('Outlet Bills')
        ->assertSee('Cafe')
        ->assertSee('2 x Coffee, 1 x Cake')
        ->assertSee('Outlet Bills Total');
});
