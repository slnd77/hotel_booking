<?php

use App\Enums\BookingStatus;
use App\Models\Currency;
use App\Models\Guest;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Storage::fake('local');
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create();
    $this->generalField = MetaField::query()->firstOrCreate(['key' => 'invoice_settings_general'], ['key_label' => 'Invoice Settings', 'status' => true]);

    $category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    $room = Room::factory()->create(['room_category_id' => $category->id]);
    $this->booking = $this->property->bookings()->create([
        'booking_ref_id' => 'INV-SET', 'source' => 'direct', 'type' => 'walk_in', 'status' => BookingStatus::Confirmed,
        'check_in' => now()->addDay()->toDateString(), 'check_out' => now()->addDays(2)->toDateString(), 'check_out_time' => '11:00',
        'room_total' => 1000, 'discount' => 0, 'taxes' => 0, 'total' => 1000, 'outstanding' => 1000,
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
    ]);
    $this->booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => $room->id, 'meal_plan' => 'EP', 'adults' => 1, 'rate_per_night' => 1000]);
});

function invoiceHtml(): string
{
    return test()->actingAs(test()->admin)
        ->get(route('admin.bookings.print', [test()->property, test()->booking, 'room-invoice']).'?preview=1')
        ->assertOk()
        ->getContent();
}

function generalSettings(): array
{
    return json_decode((string) test()->property->metaFieldValues()->where('meta_fields_key_id', test()->generalField->id)->value('data'), true) ?: [];
}

test('an invoice logo and digital signature upload, preview, print on the invoice, and survive saving the page', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.invoiceSettings.images.store', $this->property), ['type' => 'invoice_logo', 'image' => UploadedFile::fake()->image('logo.png', 300, 100)])
        ->assertSessionHas('success');
    $this->actingAs($this->admin)
        ->post(route('admin.invoiceSettings.images.store', $this->property), ['type' => 'digital_signature', 'image' => UploadedFile::fake()->image('sign.png', 200, 60)])
        ->assertSessionHas('success');

    $logoPath = generalSettings()['invoice_logo_path'];
    Storage::disk('local')->assertExists($logoPath);

    $this->actingAs($this->admin)->get(route('admin.invoiceSettings.images.show', [$this->property, 'invoice_logo']))->assertOk();
    $this->actingAs($this->admin)
        ->get(route('admin.invoiceSettings.edit', $this->property))
        ->assertInertia(fn ($page) => $page->where('images.invoice_logo', fn (?string $url): bool => filled($url))->where('images.digital_signature', fn (?string $url): bool => filled($url)));

    // Embedded, so PDFs and e-mailed invoices show them too.
    expect(invoiceHtml())->toContain('data:image/png;base64,')
        ->toContain('Authorised Signatory');

    // Saving the rest of the page (which doesn't send the images) keeps them.
    $this->actingAs($this->admin)->put(route('admin.invoiceSettings.update', $this->property), [
        'meta_fields' => [$this->generalField->id => ['enabled' => true, 'data' => json_encode(['parent_company' => 'Seaside Group'])]],
    ])->assertRedirect();

    expect(generalSettings())->toMatchArray(['parent_company' => 'Seaside Group', 'invoice_logo_path' => $logoPath]);
});

test('replacing or removing an image deletes the old file, and only images are accepted', function () {
    $this->actingAs($this->admin)->post(route('admin.invoiceSettings.images.store', $this->property), ['type' => 'invoice_logo', 'image' => UploadedFile::fake()->image('a.png')]);
    $first = generalSettings()['invoice_logo_path'];

    $this->actingAs($this->admin)->post(route('admin.invoiceSettings.images.store', $this->property), ['type' => 'invoice_logo', 'image' => UploadedFile::fake()->image('b.png')]);
    Storage::disk('local')->assertMissing($first);

    $this->actingAs($this->admin)->delete(route('admin.invoiceSettings.images.destroy', [$this->property, 'invoice_logo']))->assertSessionHas('success');
    expect(generalSettings()['invoice_logo_path'])->toBeNull()
        ->and(invoiceHtml())->not->toContain('data:image');

    $this->actingAs($this->admin)
        ->post(route('admin.invoiceSettings.images.store', $this->property), ['type' => 'invoice_logo', 'image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('image');
});

test('the guest company prints with its registration number under the Co. Registration No. label', function () {
    $this->booking->guests()->attach(Guest::factory()->create([
        'property_id' => $this->property->id,
        'company' => ['name' => 'Acme Travels', 'gstin' => '27AAAPA1234A1Z5', 'email' => '', 'integration_id' => '', 'address' => 'MG Road, Pune'],
    ]));
    $this->property->metaFieldValues()->create(['meta_fields_key_id' => $this->generalField->id, 'enabled' => true, 'data' => json_encode(['co_registration_label' => 'Company GSTIN'])]);

    expect(invoiceHtml())
        ->toContain('Acme Travels')
        ->toContain('Company GSTIN</span> : 27AAAPA1234A1Z5')
        ->toContain('MG Road, Pune');
});

test('individual invoices print one invoice per room from its own folio; consolidated prints one for the booking', function () {
    $category = RoomCategory::query()->where('property_id', $this->property->id)->first();
    $secondRoom = Room::factory()->create(['room_category_id' => $category->id]);
    $this->booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => $secondRoom->id, 'meal_plan' => 'EP', 'adults' => 1, 'rate_per_night' => 500]);
    $this->booking->update(['room_total' => 1500, 'total' => 1500, 'outstanding' => 1500]);

    // Consolidated (the default): one invoice for the whole booking.
    $html = invoiceHtml();
    expect(substr_count($html, 'class="invoice-section"'))->toBe(1)
        ->and($html)->toContain('Room charges only — outlet charges are invoiced separately')
        ->toContain('USD 1,500.00');

    $this->property->metaFieldValues()->create(['meta_fields_key_id' => $this->generalField->id, 'enabled' => true, 'data' => json_encode([
        'invoice_billing_method' => 'individual_invoices',
        'billing_method_invoice_type' => 'consolidated_invoice',
    ])]);

    // Individual: one per room, each with that room's own charges and amount due.
    $html = invoiceHtml();
    expect(substr_count($html, 'class="invoice-section"'))->toBe(2)
        ->and($html)->toContain('Room 1 | ')
        ->toContain('Room 2 | ')
        ->toContain('Individual invoice 1 of 2')
        ->toContain('Consolidated invoice (room and outlet charges)')
        ->toContain('USD 1,000.00')
        ->toContain('USD 500.00');
});

test('parent company, check-out preference and the invoice series print on the invoice', function () {
    $this->property->metaFieldValues()->create(['meta_fields_key_id' => $this->generalField->id, 'enabled' => true, 'data' => json_encode([
        'parent_company' => 'Seaside Group',
        'checkin_checkout_preference' => 'property_checkout_time',
        'invoice_start_range' => 'SI-0001',
        'invoice_end_range' => 'SI-9999',
        'invoice_bir_permit_display_name' => 'PTU No.',
        'invoice_bir_permit_no' => 'FP-12345',
        'invoice_owned_by' => 'Seaside Hotels Inc.',
    ])]);

    expect(invoiceHtml())
        ->toContain('A unit of Seaside Group')
        // The property's check-out time, even though no one has checked out yet.
        ->toContain(now()->addDays(2)->format('d-M-Y').' ; 11:00 am')
        ->toContain('Invoice series: SI-0001 – SI-9999')
        ->toContain('PTU No. FP-12345')
        ->toContain('Owned by: Seaside Hotels Inc.');
});
