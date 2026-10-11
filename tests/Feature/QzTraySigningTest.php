<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->directory = storage_path('framework/testing/qz-'.uniqid());
    config([
        'services.qz_tray.certificate' => "{$this->directory}/digital-certificate.txt",
        'services.qz_tray.private_key' => "{$this->directory}/private-key.pem",
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

test('without a key pair QZ Tray gets no certificate or signature, so it asks the user instead', function () {
    $this->actingAs($this->admin)->get(route('admin.pos.qz.certificate'))->assertOk()->assertContent('');
    $this->actingAs($this->admin)->post(route('admin.pos.qz.sign'), ['request' => 'abc'])->assertOk()->assertContent('');
});

test('the command makes a key pair once, and print requests are signed with it', function () {
    $this->artisan('pos:qz-certificate', ['--name' => 'pos.example.test'])->assertSuccessful();
    $this->artisan('pos:qz-certificate')->assertFailed();

    $certificate = $this->actingAs($this->admin)->get(route('admin.pos.qz.certificate'))->assertOk()->getContent();
    expect($certificate)->toStartWith('-----BEGIN CERTIFICATE-----')
        ->and(openssl_x509_parse($certificate)['subject']['CN'])->toBe('pos.example.test');

    $toSign = '{"call":"print","timestamp":1760000000000}';
    $signature = $this->actingAs($this->admin)->post(route('admin.pos.qz.sign'), ['request' => $toSign])->assertOk()->getContent();

    expect(openssl_verify($toSign, base64_decode($signature), openssl_pkey_get_public($certificate), OPENSSL_ALGO_SHA512))->toBe(1);
});

test('only staff who may post POS transactions get print requests signed', function () {
    $this->artisan('pos:qz-certificate')->assertSuccessful();

    $housekeeper = User::factory()->create();
    $housekeeper->assignRole(Role::findOrCreate('Housekeeper', 'web'));

    $this->actingAs($housekeeper)->post(route('admin.pos.qz.sign'), ['request' => 'abc'])->assertForbidden();
    auth()->logout();
    $this->post(route('admin.pos.qz.sign'), ['request' => 'abc'])->assertRedirect();
});
