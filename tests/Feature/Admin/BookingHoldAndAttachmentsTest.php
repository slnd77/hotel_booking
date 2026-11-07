<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Currency;
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
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create();
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    Room::factory()->count(2)->create(['room_category_id' => $this->category->id]);

    enableEmailNotifications();

    $this->checkIn = now()->addDays(3)->toDateString();
    $this->checkOut = now()->addDays(4)->toDateString();
});

/**
 * @param  list<array<string, mixed>>  $documents
 */
function saveDocumentSettings(Property $property, array $documents): void
{
    $metaField = MetaField::query()->firstOrCreate(['key' => 'booking_settings_document'], ['key_label' => 'Document Settings', 'status' => true]);

    $property->metaFieldValues()->updateOrCreate(
        ['meta_fields_key_id' => $metaField->id],
        ['enabled' => true, 'data' => json_encode(['documents' => $documents])],
    );
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function holdTestPayload(RoomCategory $category, string $checkIn, string $checkOut, array $overrides = []): array
{
    return [
        'booking_ref_id' => '',
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'status' => 'confirmed',
        'discount' => 0,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
        'rooms' => [['room_category_id' => $category->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
        ...$overrides,
    ];
}

test('quick book opens in hold mode with auto release defaults and enabled document types', function () {
    saveDocumentSettings($this->property, [
        ['name' => 'Passport', 'status' => true, 'folio_document' => true, 'require_expiry_date' => true, 'require_document_number' => true],
        ['name' => 'Aadhaar Card', 'status' => false, 'folio_document' => true, 'require_expiry_date' => false, 'require_document_number' => false],
        ['name' => 'Visa', 'status' => true, 'folio_document' => true, 'require_expiry_date' => false, 'require_document_number' => false],
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.create', ['property' => $this->property, 'hold' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('isHold', true)
            ->where('documentTypes', fn ($types): bool => ! collect($types)->contains('name', 'Aadhaar Card')
                && collect($types)->contains('name', 'Visa')
                && collect($types)->firstWhere('name', 'Passport')['require_document_number'] === true));

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.create', $this->property))
        ->assertInertia(fn ($page) => $page->where('isHold', false));
});

test('a held booking stores its release time, reminders and auto release', function () {
    $releaseAt = now()->addDays(2)->setTime(12, 0)->format('Y-m-d\TH:i');

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), holdTestPayload($this->category, $this->checkIn, $this->checkOut, [
            'status' => 'on_hold',
            'hold_release_at' => $releaseAt,
            'hold_auto_release' => true,
            'hold_reminders' => [['channel' => 'email', 'days' => 1, 'relative_to' => 'hold_release_date']],
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $booking = Booking::sole();

    expect($booking->status)->toBe(BookingStatus::OnHold)
        ->and($booking->hold_release_at->format('Y-m-d\TH:i'))->toBe($releaseAt)
        ->and($booking->hold_auto_release)->toBeTrue()
        ->and($booking->hold_reminders)->toBe([['channel' => 'email', 'days' => 1, 'relative_to' => 'hold_release_date']]);
});

test('a held booking needs a release time in the future', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), holdTestPayload($this->category, $this->checkIn, $this->checkOut, [
            'status' => 'on_hold',
            'hold_release_at' => now()->subHour()->format('Y-m-d\TH:i'),
        ]))
        ->assertSessionHasErrors('hold_release_at');

    expect(Booking::count())->toBe(0);
});

test('attachments are stored privately and follow the document settings', function () {
    Storage::fake('local');
    saveDocumentSettings($this->property, [
        ['name' => 'Passport', 'status' => true, 'folio_document' => true, 'require_expiry_date' => true, 'require_document_number' => true],
        ['name' => 'Aadhaar Card', 'status' => false, 'folio_document' => true, 'require_expiry_date' => false, 'require_document_number' => false],
    ]);

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), holdTestPayload($this->category, $this->checkIn, $this->checkOut, [
            'attachments' => [['document_type' => 'Passport', 'document_number' => '', 'expiry_date' => '', 'file' => UploadedFile::fake()->create('passport.pdf', 200, 'application/pdf')]],
        ]))
        ->assertSessionHasErrors(['attachments.0.document_number', 'attachments.0.expiry_date']);

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), holdTestPayload($this->category, $this->checkIn, $this->checkOut, [
            'attachments' => [['document_type' => 'Aadhaar Card', 'document_number' => 'X1', 'expiry_date' => '', 'file' => UploadedFile::fake()->create('aadhaar.pdf', 200, 'application/pdf')]],
        ]))
        ->assertSessionHasErrors('attachments.0.document_type');

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), holdTestPayload($this->category, $this->checkIn, $this->checkOut, [
            'attachments' => [[
                'document_type' => 'Passport',
                'document_number' => 'P1234567',
                'expiry_date' => now()->addYears(3)->toDateString(),
                'file' => UploadedFile::fake()->create('passport.pdf', 200, 'application/pdf'),
            ]],
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $attachment = Booking::sole()->attachments()->sole();

    expect($attachment->document_type)->toBe('Passport')
        ->and($attachment->document_number)->toBe('P1234567')
        ->and($attachment->original_name)->toBe('passport.pdf')
        ->and($attachment->user_id)->toBe($this->admin->id);

    Storage::disk('local')->assertExists($attachment->path);
});

test('expired holds marked for auto release are cancelled, others are kept', function () {
    $makeHold = fn (bool $autoRelease, string $releaseAt): Booking => $this->property->bookings()->create([
        'booking_ref_id' => fake()->unique()->bothify('HOLD####'),
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => $this->checkIn,
        'check_out' => $this->checkOut,
        'status' => BookingStatus::OnHold,
        'hold_release_at' => $releaseAt,
        'hold_auto_release' => $autoRelease,
        'room_total' => 1000,
        'taxes' => 0,
        'total' => 1000,
        'outstanding' => 1000,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
    ]);

    $expired = $makeHold(true, now()->subMinute()->toDateTimeString());
    $manual = $makeHold(false, now()->subMinute()->toDateTimeString());
    $upcoming = $makeHold(true, now()->addHour()->toDateTimeString());

    $this->artisan('bookings:release-expired-holds')->assertSuccessful();

    expect($expired->fresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($manual->fresh()->status)->toBe(BookingStatus::OnHold)
        ->and($upcoming->fresh()->status)->toBe(BookingStatus::OnHold);
});

test('uploaded documents are listed on the booking and can be viewed inline or downloaded', function () {
    Storage::fake('local');

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), holdTestPayload($this->category, $this->checkIn, $this->checkOut, [
            'attachments' => [['document_type' => 'Passport', 'document_number' => 'P1', 'expiry_date' => '', 'file' => UploadedFile::fake()->image('passport.jpg')]],
        ]))
        ->assertSessionHasNoErrors();

    $booking = Booking::sole();
    $attachment = $booking->attachments()->sole();

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.show', [$this->property, $booking]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('booking.attachments.0.document_type', 'Passport')
            ->where('booking.attachments.0.url', route('admin.bookings.attachments.show', [$this->property, $booking, $attachment])));

    $inline = $this->actingAs($this->admin)->get(route('admin.bookings.attachments.show', [$this->property, $booking, $attachment]));
    $inline->assertOk();
    expect($inline->headers->get('Content-Disposition'))->toStartWith('inline');

    $download = $this->actingAs($this->admin)->get(route('admin.bookings.attachments.show', [$this->property, $booking, $attachment, 'download' => 1]));
    expect($download->headers->get('Content-Disposition'))->toStartWith('attachment');
});

test('a document cannot be viewed through another booking', function () {
    Storage::fake('local');

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), holdTestPayload($this->category, $this->checkIn, $this->checkOut, [
            'attachments' => [['document_type' => 'Passport', 'document_number' => 'P1', 'expiry_date' => '', 'file' => UploadedFile::fake()->image('passport.jpg')]],
        ]))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), holdTestPayload($this->category, $this->checkIn, $this->checkOut))
        ->assertSessionHasNoErrors();

    [$withDocument, $otherBooking] = Booking::query()->orderBy('id')->get()->all();
    $attachment = $withDocument->attachments()->sole();

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.attachments.show', [$this->property, $otherBooking, $attachment]))
        ->assertNotFound();
});
