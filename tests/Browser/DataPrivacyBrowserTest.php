<?php

use App\Models\AccountDataRequest;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\User;
use App\Services\ApplicationSettings;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);
    Storage::fake('local');

    $this->admin = User::factory()->create(['password' => 'password']);
    $this->admin->assignRole('admin');
    Property::factory()->create(['timezone' => 'UTC']);
});

test('the owner sets retention, exports the data and schedules then cancels the account deletion', function () {
    $this->actingAs($this->admin);

    $page = visit(route('admin.dataPrivacy.edit'))
        ->assertSee('Data & privacy')
        ->type('@retention-guest-months', '36')
        ->type('@retention-id-days', '30')
        ->click('@retention-save')
        ->assertSee('Retention policy saved.')
        ->click('@export-request')
        ->assertSee('The export is being prepared.');

    expect(app(ApplicationSettings::class)->get(ApplicationSettings::DATA_RETENTION))->toMatchArray(['guest_data_months' => 36, 'id_document_days' => 30, 'activity_log_months' => null])
        ->and(AccountDataRequest::query()->where('type', AccountDataRequest::EXPORT)->value('status'))->toBe(AccountDataRequest::READY);

    $page->click('@deletion-open')
        ->type('@deletion-password', 'password')
        ->type('@deletion-confirmation', 'DELETE')
        ->click('@deletion-submit')
        ->assertVisible('@deletion-scheduled')
        ->assertSee('and all its data will be deleted on')
        ->click('@deletion-cancel')
        ->assertSee('The account deletion was cancelled.')
        ->assertMissing('@deletion-scheduled')
        ->assertNoJavaScriptErrors();

    expect(AccountDataRequest::query()->where('type', AccountDataRequest::DELETION)->value('status'))->toBe(AccountDataRequest::CANCELLED);
});
