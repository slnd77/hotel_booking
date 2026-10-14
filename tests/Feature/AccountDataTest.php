<?php

use App\Enums\BookingStatus;
use App\Models\AccountDataRequest;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Guest;
use App\Models\Language;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\User;
use App\Models\WebCheckin;
use App\Services\ApplicationSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);
    Storage::fake('local');
    Storage::fake('public');

    $this->admin = User::factory()->create(['password' => 'password']);
    $this->admin->assignRole('admin');
    $this->property = Property::factory()->create(['timezone' => 'UTC']);
});

/**
 * A booking of the guest whose stay ended (or starts) that many days from today.
 */
function retentionBooking(Property $property, Guest $guest, int $checkOutDays, BookingStatus $status = BookingStatus::CheckedOut): Booking
{
    $category = RoomCategory::factory()->create(['property_id' => $property->id]);

    return DB::transaction(function () use ($property, $guest, $checkOutDays, $status, $category): Booking {
        $booking = $property->bookings()->create([
            'booking_ref_id' => 'RET-'.fake()->unique()->numerify('####'), 'source' => 'direct', 'status' => $status,
            'check_in' => now()->addDays($checkOutDays - 2)->toDateString(), 'check_out' => now()->addDays($checkOutDays)->toDateString(),
            'room_total' => 2000, 'discount' => 0, 'taxes' => 0, 'total' => 2000, 'outstanding' => 2000,
            'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        ]);
        $booking->rooms()->create(['room_category_id' => $category->id, 'meal_plan' => 'EP', 'adults' => 1, 'rate_per_night' => 1000]);
        $booking->guests()->attach($guest->id);

        return $booking;
    })->fresh();
}

test('the retention policy erases old guests, ID documents and logs, and keeps what is still needed', function () {
    $this->actingAs($this->admin)->put(route('admin.dataPrivacy.retention.update'), ['guest_data_months' => 3])->assertSessionHasErrors('guest_data_months');
    $this->actingAs($this->admin)->put(route('admin.dataPrivacy.retention.update'), ['guest_data_months' => 24, 'id_document_days' => 30, 'activity_log_months' => 12])
        ->assertSessionHasNoErrors();

    $this->travel(-3)->years();
    $old = Guest::factory()->create(['property_id' => $this->property->id, 'first_name' => 'Old', 'phone' => '9000000001']);
    $returning = Guest::factory()->create(['property_id' => $this->property->id, 'first_name' => 'Returning']);
    $upcoming = Guest::factory()->create(['property_id' => $this->property->id, 'first_name' => 'Upcoming']);
    $oldLog = ActivityLog::create(['user_id' => $this->admin->id, 'property_id' => $this->property->id, 'action' => 'Old entry', 'date' => now()]);
    $this->travelBack();
    $this->travelTo(now()->utc()->setTime(6, 0));

    retentionBooking($this->property, $old, -800);
    retentionBooking($this->property, $returning, -800);
    retentionBooking($this->property, $returning, -10);
    retentionBooking($this->property, $upcoming, 20, BookingStatus::Confirmed);
    $new = Guest::factory()->create(['property_id' => $this->property->id, 'first_name' => 'New']);
    $recentLog = ActivityLog::create(['user_id' => $this->admin->id, 'property_id' => $this->property->id, 'action' => 'Recent entry', 'date' => now()]);

    Storage::disk('local')->put('web-checkins/old.jpg', 'id');
    Storage::disk('local')->put('web-checkins/recent.jpg', 'id');
    $oldCheckin = WebCheckin::query()->create(['property_id' => $this->property->id, 'booking_id' => retentionBooking($this->property, $new, -40)->id, 'token' => 'tok-old', 'status' => WebCheckin::COMPLETED, 'id_document_path' => 'web-checkins/old.jpg']);
    $recentCheckin = WebCheckin::query()->create(['property_id' => $this->property->id, 'booking_id' => retentionBooking($this->property, $new, -5)->id, 'token' => 'tok-new', 'status' => WebCheckin::COMPLETED, 'id_document_path' => 'web-checkins/recent.jpg']);

    $this->artisan('account-data:process')->assertSuccessful();

    expect($old->fresh()->first_name)->toBe('Erased')
        ->and($old->fresh()->phone)->toBeNull()
        ->and($old->bookings()->count())->toBe(1)
        ->and($returning->fresh()->first_name)->toBe('Returning')
        ->and($upcoming->fresh()->first_name)->toBe('Upcoming')
        ->and($new->fresh()->first_name)->toBe('New')
        ->and($oldCheckin->fresh()->id_document_path)->toBeNull()
        ->and($recentCheckin->fresh()->id_document_path)->toBe('web-checkins/recent.jpg')
        ->and(ActivityLog::query()->whereKey($oldLog->id)->exists())->toBeFalse()
        ->and(ActivityLog::query()->whereKey($recentLog->id)->exists())->toBeTrue()
        ->and(app(ApplicationSettings::class)->logs(ApplicationSettings::DATA_RETENTION)->first()['description'])->toContain('1 guest(s)\' personal data erased, 1 ID document(s) deleted');
    Storage::disk('local')->assertMissing('web-checkins/old.jpg');
    Storage::disk('local')->assertExists('web-checkins/recent.jpg');
});

test('with no retention set, nothing is removed', function () {
    $this->travel(-5)->years();
    $guest = Guest::factory()->create(['property_id' => $this->property->id, 'first_name' => 'Kept']);
    $this->travelBack();

    $this->artisan('account-data:process')->assertSuccessful();

    expect($guest->fresh()->first_name)->toBe('Kept');
});

test('the account owner exports all the account data as a ZIP, without secrets, for a few days', function () {
    $guest = Guest::factory()->create(['property_id' => $this->property->id, 'first_name' => 'Meera']);
    $booking = retentionBooking($this->property, $guest, -1);
    Storage::disk('public')->put('room-category-images/room.jpg', 'image');

    $this->actingAs($this->admin)->post(route('admin.dataPrivacy.exports.store'))->assertSessionHas('success');

    $export = AccountDataRequest::query()->sole();
    expect($export->status)->toBe(AccountDataRequest::READY)
        ->and($export->expires_at->isSameDay(now()->addDays(7)))->toBeTrue();

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($export->file_path));
    $bookings = $zip->getFromName('data/bookings.csv');
    $users = str_getcsv(strtok((string) $zip->getFromName('data/users.csv'), "\n"), escape: '');
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);

    expect($bookings)->toContain($booking->booking_ref_id)
        ->and($zip->getFromName('data/guests.csv'))->toContain('Meera')
        ->and($users)->toContain('email')->not->toContain('password')->not->toContain('two_factor_secret')
        ->and($zip->getFromName('files/public/room-category-images/room.jpg'))->toBe('image')
        ->and($zip->locateName('data/countries.csv'))->toBeFalse()
        ->and($manifest['tables']['bookings'])->toBe(1);
    $zip->close();

    $this->get(route('admin.dataPrivacy.exports.download', $export))->assertOk()->assertDownload('account-data-'.now()->format('Y-m-d').'.zip');

    $this->travel(8)->days();
    $this->artisan('account-data:process');

    expect($export->fresh()->status)->toBe(AccountDataRequest::EXPIRED);
    Storage::disk('local')->assertMissing($export->file_path);
    $this->get(route('admin.dataPrivacy.exports.download', $export))->assertNotFound();
});

test('the account is deleted after the grace period unless cancelled', function () {
    $country = Country::query()->create(['name' => 'Kept Country', 'iso2' => 'KC', 'iso3' => 'KCY', 'phone_code' => '0']);
    $guest = Guest::factory()->create(['property_id' => $this->property->id]);
    retentionBooking($this->property, $guest, -1);
    Storage::disk('local')->put('booking-attachments/1/file.pdf', 'pdf');

    $this->actingAs($this->admin)->post(route('admin.dataPrivacy.deletion.store'), ['password' => 'wrong', 'confirmation' => 'DELETE'])->assertSessionHasErrors('password');
    $this->actingAs($this->admin)->post(route('admin.dataPrivacy.deletion.store'), ['password' => 'password', 'confirmation' => 'delete'])->assertSessionHasErrors('confirmation');
    $this->actingAs($this->admin)->post(route('admin.dataPrivacy.deletion.store'), ['password' => 'password', 'confirmation' => 'DELETE'])->assertSessionHas('warning');

    $deletion = AccountDataRequest::scheduledDeletion();
    expect($deletion->scheduled_for->isSameDay(now()->addDays(30)))->toBeTrue();

    // Cancelled: nothing happens on the day.
    $this->actingAs($this->admin)->delete(route('admin.dataPrivacy.deletion.destroy', $deletion))->assertSessionHas('success');
    $this->travel(31)->days();
    $this->artisan('account-data:process');
    expect(Booking::query()->count())->toBe(1);

    $this->actingAs($this->admin)->post(route('admin.dataPrivacy.deletion.store'), ['password' => 'password', 'confirmation' => 'DELETE']);
    $this->travel(29)->days();
    $this->artisan('account-data:process');
    expect(Booking::query()->count())->toBe(1);

    $this->travel(2)->days();
    $this->artisan('account-data:process')->assertSuccessful();

    expect(Booking::query()->count())->toBe(0)
        ->and(Guest::query()->count())->toBe(0)
        ->and(Property::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and(DB::table('booking_guests')->count())->toBe(0)
        ->and(DB::table('model_has_roles')->count())->toBe(0)
        ->and(Country::query()->whereKey($country->id)->exists())->toBeTrue()
        ->and(AccountDataRequest::query()->where('status', AccountDataRequest::COMPLETED)->count())->toBe(1);
    Storage::disk('local')->assertMissing('booking-attachments/1/file.pdf');
});

test('data & privacy needs its permission, and only the account owner deletes the account', function () {
    $clerk = User::factory()->create(['password' => 'password']);
    $clerk->assignRole(Role::findOrCreate('Clerk', 'web'));

    $this->actingAs($clerk)->get(route('admin.dataPrivacy.edit'))->assertForbidden();
    $this->actingAs($clerk)->post(route('admin.dataPrivacy.exports.store'))->assertForbidden();

    $clerk->givePermissionTo(Permission::findOrCreate('manage_account.data_privacy', 'web'));

    $this->actingAs($clerk)->get(route('admin.dataPrivacy.edit'))->assertOk()->assertInertia(fn ($page) => $page->component('admin/data-privacy/Edit')->where('canDelete', false));
    $this->actingAs($clerk)->post(route('admin.dataPrivacy.deletion.store'), ['password' => 'password', 'confirmation' => 'DELETE'])->assertForbidden();
    expect(AccountDataRequest::query()->count())->toBe(0);
});
