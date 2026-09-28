<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create(['name' => 'Front Desk']);
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create();
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id, 'name' => 'Super Deluxe']);
    $this->room = Room::factory()->create(['room_category_id' => $this->category->id, 'name' => 'SDTA 2']);

    $this->booking = $this->property->bookings()->create([
        'booking_ref_id' => 'PRINTME',
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => now()->addDays(3)->toDateString(),
        'check_out' => now()->addDays(5)->toDateString(),
        'status' => BookingStatus::Confirmed,
        'room_total' => 2000,
        'discount' => 0,
        'taxes' => 0,
        'total' => 2000,
        'outstanding' => 2000,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
        'internal_notes' => 'VIP — upgrade if free',
    ]);
    $this->booking->rooms()->create(['room_category_id' => $this->category->id, 'room_id' => $this->room->id, 'meal_plan' => 'EP', 'adults' => 2, 'rate_per_night' => 1000]);
});

/**
 * @param  array<string, mixed>  $data
 */
function saveInvoiceSettings(Property $property, array $data): void
{
    $field = MetaField::query()->firstOrCreate(['key' => 'invoice_settings_general'], ['key_label' => 'Invoice Settings', 'status' => true]);
    $property->metaFieldValues()->updateOrCreate(['meta_fields_key_id' => $field->id], ['enabled' => true, 'data' => json_encode($data)]);
}

function printUrl(Property $property, Booking $booking, string $document, bool $preview = true): string
{
    return route('admin.bookings.print', [$property, $booking, $document]).($preview ? '?preview=1' : '');
}

test('every print document renders for the booking', function (string $document, string $expected) {
    $this->actingAs($this->admin)
        ->get(printUrl($this->property, $this->booking, $document))
        ->assertOk()
        ->assertSee('PRINTME')
        ->assertSee($expected)
        ->assertDontSee('window.print(), 300', false);
})->with([
    'GRC' => ['grc', 'Guest Reservation Card'],
    'reservation internal' => ['reservation-internal', 'Reservation'],
    'reservation guest' => ['reservation-guest', 'Reservation'],
    'bill summary' => ['bill-summary', 'Bill Summary'],
    'room invoice' => ['room-invoice', 'Room Proforma Invoice'],
]);

test('opening a print without preview starts the print dialog', function () {
    $this->actingAs($this->admin)
        ->get(printUrl($this->property, $this->booking, 'reservation-guest', preview: false))
        ->assertOk()
        ->assertSee('window.print(), 300', false);
});

test('internal notes and room numbers print only on the internal reservation', function () {
    $this->actingAs($this->admin)
        ->get(printUrl($this->property, $this->booking, 'reservation-internal'))
        ->assertSee('VIP — upgrade if free')
        ->assertSee('SDTA 2');

    $this->actingAs($this->admin)
        ->get(printUrl($this->property, $this->booking, 'reservation-guest'))
        ->assertDontSee('VIP — upgrade if free')
        ->assertDontSee('SDTA 2');
});

test('print headers, statutory IDs and footers follow the invoice settings', function () {
    saveInvoiceSettings($this->property, [
        'statutory_ids' => [['id' => 1, 'display_name' => 'GSTN', 'display_value' => '19AAATI0017P1ZK']],
        'print_headers' => [
            ['key' => 'reservation', 'value' => 'Booking Voucher', 'enabled' => true],
            ['key' => 'room_invoice', 'value' => 'Tax Invoice', 'enabled' => true],
            ['key' => 'bill_summary', 'value' => 'Bill Summary', 'enabled' => false],
        ],
        'print_footer_toggles_b' => [['key' => 'show_footer_signature', 'enabled' => true]],
        'print_footer_watermarks' => [['key' => 'watermark_bill_summary', 'value' => 'DUPLICATE', 'enabled' => true]],
        'guest_liability_enabled' => true,
        'guest_liability_text' => 'Guest is liable for damages.',
    ]);
    Invoice::generateForBooking($this->booking, 'Front Desk');

    $this->actingAs($this->admin)
        ->get(printUrl($this->property, $this->booking, 'reservation-guest'))
        ->assertSee('Booking Voucher');

    $this->actingAs($this->admin)
        ->get(printUrl($this->property, $this->booking, 'bill-summary'))
        ->assertDontSee('Bill Summary</div>', false)
        ->assertSee('DUPLICATE');

    $this->actingAs($this->admin)
        ->get(printUrl($this->property, $this->booking, 'room-invoice'))
        ->assertSee('Tax Invoice')
        ->assertDontSee('PROFORMA INVOICE')
        ->assertDontSee('This is not a tax invoice')
        ->assertSee('GSTN')
        ->assertSee('19AAATI0017P1ZK')
        ->assertSee('INV')
        ->assertSee('SIGNATURE')
        ->assertSee('Guest is liable for damages.');
});

test('the GRC leaves out fields turned off in the GRC settings', function () {
    $field = MetaField::query()->firstOrCreate(['key' => 'GRC_settings'], ['key_label' => 'GRC', 'status' => true]);
    $this->property->metaFieldValues()->create([
        'meta_fields_key_id' => $field->id,
        'enabled' => true,
        'data' => json_encode(['print_header' => 'Registration Card', 'fields' => [['name' => 'Vehicle Number', 'enabled' => false], ['name' => 'Email', 'enabled' => true]]]),
    ]);

    $this->actingAs($this->admin)
        ->get(printUrl($this->property, $this->booking, 'grc'))
        ->assertSee('Registration Card')
        ->assertSee('Email')
        ->assertDontSee('Vehicle Number');
});

test('bookings of another property and unknown documents are not found', function () {
    $otherProperty = Property::factory()->create();

    $this->actingAs($this->admin)->get(printUrl($otherProperty, $this->booking, 'grc'))->assertNotFound();
    $this->actingAs($this->admin)->get(printUrl($this->property, $this->booking, 'extras-invoice'))->assertNotFound();
});

test('the room invoice breaks taxes up per component when the tax rule matches the saved taxes', function () {
    $category = MetaField::query()->firstOrCreate(['key' => 'tax_settings_tax_category'], ['key_label' => 'Tax Category', 'status' => true]);
    $rule = MetaField::query()->firstOrCreate(['key' => 'tax_settings_tax_rule'], ['key_label' => 'Tax Rule', 'status' => true]);
    $component = fn (string $name): array => ['component_choice' => $name, 'applied_on' => 'rack_rate', 'tax_value_type' => 'percentage', 'tax_value' => 6, 'define_tax_slab' => false];
    $this->property->metaFieldValues()->create(['meta_fields_key_id' => $category->id, 'enabled' => true, 'data' => json_encode(['categories' => [['id' => 1, 'name' => 'Room']]])]);
    $this->property->metaFieldValues()->create(['meta_fields_key_id' => $rule->id, 'enabled' => true, 'data' => json_encode(['rules' => [
        ['tax_category_id' => 1, 'deleted' => false, 'start_date' => null, 'end_date' => null, 'components' => [$component('CGST'), $component('SGST')]],
    ]])]);

    $this->booking->update(['taxes' => 240, 'total' => 2240]);

    $this->actingAs($this->admin)
        ->get(printUrl($this->property, $this->booking, 'room-invoice'))
        ->assertSee('CGST - Super Deluxe')
        ->assertSee('SGST - Super Deluxe')
        ->assertSee('SAC Code: 996311');

    $this->booking->update(['taxes' => 300, 'total' => 2300]);

    $this->actingAs($this->admin)
        ->get(printUrl($this->property, $this->booking, 'room-invoice'))
        ->assertDontSee('CGST - Super Deluxe')
        ->assertSee('Taxes for');
});

test('a print document downloads as a PDF of the same page', function () {
    Pdf::fake();

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.printPdf', [$this->property, $this->booking, 'room-invoice']))
        ->assertOk();

    Pdf::assertRespondedWithPdf(fn (PdfBuilder $pdf): bool => $pdf->isDownload()
        && $pdf->downloadName === 'room-invoice-PRINTME.pdf'
        && $pdf->contains('Room Proforma Invoice')
        && $pdf->contains('This is not a tax invoice')
        && ! $pdf->contains('Download PDF'));
});

test('the print page links to its PDF download', function () {
    $this->actingAs($this->admin)
        ->get(printUrl($this->property, $this->booking, 'grc'))
        ->assertSee(route('admin.bookings.printPdf', [$this->property, $this->booking, 'grc']), false);
});

test('prints use the symbol of the property\'s configured currency, not its country\'s', function () {
    $euro = Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'EUR']);
    DB::table('countries')->insert([
        ['name' => 'Germany', 'iso3' => 'DEU', 'iso2' => 'DE', 'currency' => 'EUR', 'currency_symbol' => '€'],
        ['name' => 'India', 'iso3' => 'IND', 'iso2' => 'IN', 'currency' => 'INR', 'currency_symbol' => '₹'],
    ]);
    $this->property->update(['currency_id' => $euro->id, 'country_id' => DB::table('countries')->where('iso2', 'IN')->value('id')]);

    $this->actingAs($this->admin)
        ->get(printUrl($this->property, $this->booking, 'room-invoice'))
        ->assertSee('€ 2,000.00')
        ->assertDontSee('₹');

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.show', [$this->property, $this->booking]))
        ->assertInertia(fn ($page) => $page->where('currency', ['code' => 'EUR', 'symbol' => '€']));

    $zloty = Currency::firstOrCreate(['code' => 'PLN'], ['name' => 'PLN']);
    $this->property->update(['currency_id' => $zloty->id]);

    $this->actingAs($this->admin)
        ->get(printUrl($this->property, $this->booking, 'room-invoice'))
        ->assertSee('PLN 2,000.00');
});
