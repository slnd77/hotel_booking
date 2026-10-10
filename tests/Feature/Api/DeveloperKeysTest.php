<?php

use App\Mail\ApiAccessChangedMail;
use App\Models\User;
use App\Services\ApiScopes;
use App\Services\PermissionCatalog;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Password confirmation reads the real clock (time()), not the pinned test clock.
    $this->travelBack();
    Mail::fake();

    Permission::findOrCreate('manage_account.api_keys', 'web');
    $this->owner = User::factory()->create();
    $this->owner->assignRole(Role::findOrCreate(PermissionCatalog::ACCOUNT_ADMIN, 'web')->givePermissionTo('manage_account.api_keys'));
    $this->confirmed = ['auth.password_confirmed_at' => time()];
});

function makeKey(User $user, array $scopes, ?int $days = 90): string
{
    test()->actingAs($user)->withSession(test()->confirmed)
        ->post(route('profile.developer.keys.store'), ['name' => 'Channel sync', 'scopes' => $scopes, 'expires_in_days' => $days])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.developer.index'));

    return session('api_key')['token'];
}

test('the Developer page is for roles allowed to make API keys', function () {
    $this->actingAs($this->owner)->withSession($this->confirmed)
        ->get(route('profile.developer.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('profile/Developer')
            ->where('scopes.0.value', 'account:read')
            ->has('endpoints')
            ->where('specUrl', asset('openapi.yaml')));

    $clerk = User::factory()->create();
    $clerk->assignRole(Role::findOrCreate('Front Office', 'web'));
    $this->actingAs($clerk)->withSession($this->confirmed)->get(route('profile.developer.index'))->assertForbidden();
});

test('a key is shown once, works for its scopes only, and its owner is told', function () {
    $key = makeKey($this->owner, ['account:read']);

    expect($key)->toMatch('/^\d+\|hb_/')
        ->and($this->owner->tokens()->sole()->name)->toBe(ApiScopes::KEY_PREFIX.'Channel sync');
    Mail::assertQueued(ApiAccessChangedMail::class);

    // Shown once: on the page the creation lands on, then never again.
    $this->actingAs($this->owner)->withSession($this->confirmed)->get(route('profile.developer.index'))
        ->assertInertia(fn ($page) => $page->where('newKey.token', $key));
    $this->actingAs($this->owner)->withSession($this->confirmed)->get(route('profile.developer.index'))
        ->assertInertia(fn ($page) => $page->where('newKey', null)->where('keys.0.name', 'Channel sync')->where('keys.0.scopes', ['account:read']));

    auth()->logout();
    $this->withToken($key)->getJson(route('api.v1.me'))->assertOk()->assertJsonPath('data.scopes', ['account:read']);
});

test('a key can\'t get a scope its owner\'s role doesn\'t allow', function () {
    $owner = $this->owner;

    $this->actingAs($owner)->withSession($this->confirmed)
        ->post(route('profile.developer.keys.store'), ['name' => 'Too much', 'scopes' => ['bookings:delete-everything']])
        ->assertSessionHasErrors('scopes.0');
});

test('a key loses access when its owner\'s role does', function () {
    $key = makeKey($this->owner, ['account:read']);
    auth()->logout();
    $this->withToken($key)->getJson(route('api.v1.me'))->assertOk();

    // Without the admin panel, no scope is allowed any more.
    $this->owner->syncRoles([Role::findOrCreate(PermissionCatalog::USER, 'web')]);
    app('auth')->forgetGuards();

    $this->withToken($key)->getJson(route('api.v1.me'))->assertForbidden()->assertJsonPath('message', 'Your role doesn\'t allow account:read.');
});

test('a key without the scope is refused, and an expired key stops working', function () {
    $owner = $this->owner;
    $token = $owner->createToken(ApiScopes::KEY_PREFIX.'Notes only', ['chart:notes'])->plainTextToken;

    $this->withToken($token)->getJson(route('api.v1.me'))->assertForbidden()->assertJsonPath('message', 'This API key is missing the scope: account:read.');

    $expired = $owner->createToken(ApiScopes::KEY_PREFIX.'Old', ['account:read'], now()->subMinute())->plainTextToken;
    app('auth')->forgetGuards();
    $this->withToken($expired)->getJson(route('api.v1.me'))->assertUnauthorized();
});

test('keys and devices are revoked only by their owner', function () {
    makeKey($this->owner, ['account:read']);
    $keyId = $this->owner->tokens()->sole()->id;
    $device = $this->owner->createToken(ApiScopes::DEVICE_PREFIX.'Pixel 9', ['*'])->accessToken;

    $other = User::factory()->create();
    $other->assignRole(Role::findOrCreate(PermissionCatalog::ACCOUNT_ADMIN, 'web'));
    $this->actingAs($other)->withSession($this->confirmed)->delete(route('profile.developer.keys.destroy', $keyId))->assertNotFound();

    $this->actingAs($this->owner)->withSession($this->confirmed)->delete(route('profile.developer.keys.destroy', $keyId))->assertRedirect();
    $this->actingAs($this->owner)->withSession($this->confirmed)->delete(route('profile.developer.devices.destroy', $device->id))->assertRedirect();

    expect($this->owner->tokens()->count())->toBe(0);
    Mail::assertQueued(ApiAccessChangedMail::class, 3);
});
