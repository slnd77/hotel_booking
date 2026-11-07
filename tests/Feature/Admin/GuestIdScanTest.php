<?php

use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);
    $this->property = Property::factory()->create();

    config(['services.anthropic.api_key' => 'sk-ant-test', 'services.anthropic.model' => 'claude-opus-5-5']);

    // The AI reader only: no QR or text reader on this server, whatever is installed.
    fakeIdTools(installed: false);
});

/**
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function claudeReads(array $fields): array
{
    return [
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn',
        'content' => [['type' => 'text', 'text' => json_encode([
            'document_type' => 'other', 'document_number' => null, 'first_name' => null, 'last_name' => null, 'gender' => null,
            'date_of_birth' => null, 'nationality' => null, 'address' => null, 'postal_code' => null, 'date_of_issue' => null,
            'date_of_expiry' => null, 'place_of_issue' => null, 'confidence' => 'high', 'notes' => null,
            ...$fields,
        ])]],
    ];
}

it('fills the guest form from a scanned Aadhaar card without storing the image', function () {
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeReads([
        'document_type' => 'aadhaar', 'document_number' => '1234 5678 9012', 'first_name' => 'Asha', 'last_name' => 'Rao',
        'gender' => 'Female', 'date_of_birth' => '1990-04-12', 'nationality' => 'India', 'address' => '12 MG Road, Bengaluru', 'postal_code' => '560001',
    ]))]);

    $response = $this->postJson(route('admin.guests.scanId', $this->property), ['document' => UploadedFile::fake()->image('aadhaar.jpg', 800, 500)])
        ->assertOk()
        ->assertJsonPath('scan.document_type', 'aadhaar')
        ->assertJsonPath('scan.document_label', 'Aadhaar')
        ->assertJsonPath('scan.document_number', '123456789012')
        ->assertJsonPath('guest.first_name', 'Asha')
        ->assertJsonPath('guest.last_name', 'Rao')
        ->assertJsonPath('guest.gender', 'Female')
        ->assertJsonPath('guest.dob', '1990-04-12')
        ->assertJsonPath('guest.nationality', 'India')
        ->assertJsonPath('guest.address.postal_code', '560001');

    expect($response->json('guest'))->not->toHaveKey('international_details')
        ->and(ActivityLog::query()->where('action', 'Guest ID scanned (Aadhaar, AI reading) via Web')->exists())->toBeTrue();

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'sk-ant-test')
            && $body['model'] === 'claude-opus-5-5'
            && $body['output_config']['format']['type'] === 'json_schema'
            && $body['messages'][0]['content'][0]['type'] === 'image'
            && $body['messages'][0]['content'][0]['source']['media_type'] === 'image/jpeg';
    });
});

it('puts a passport into the international details and drops dates it cannot read', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(claudeReads([
        'document_type' => 'passport', 'document_number' => 'Z1234567', 'first_name' => 'Ravi Kumar', 'last_name' => 'Nair', 'gender' => 'M',
        'nationality' => 'India', 'date_of_issue' => '2019-02-01', 'date_of_expiry' => 'not legible', 'place_of_issue' => 'Kochi',
    ]))]);

    $this->postJson(route('admin.guests.scanId', $this->property), ['document' => UploadedFile::fake()->image('passport.png')])
        ->assertOk()
        ->assertJsonPath('guest.gender', 'Male')
        ->assertJsonPath('guest.international_details.passport_number', 'Z1234567')
        ->assertJsonPath('guest.international_details.passport_issue_date', '2019-02-01')
        ->assertJsonPath('guest.international_details.passport_issue_place', 'Kochi')
        ->assertJsonMissingPath('guest.international_details.passport_validity');
});

it('rejects a photo that is not an ID, a declined request, and an unconfigured scanner', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(claudeReads(['document_type' => 'not_an_id']))
            ->push(['stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => null], 'content' => []])
            ->push(['error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401),
    ]);

    $image = UploadedFile::fake()->image('cat.jpg');

    $this->postJson(route('admin.guests.scanId', $this->property), ['document' => $image])->assertStatus(422)->assertJsonPath('message', fn (string $message): bool => str_contains($message, "doesn't look like an identity document"));
    $this->postJson(route('admin.guests.scanId', $this->property), ['document' => $image])->assertStatus(422)->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'declined'));
    $this->postJson(route('admin.guests.scanId', $this->property), ['document' => $image])->assertStatus(422)->assertJsonPath('message', fn (string $message): bool => str_contains($message, '401'));

    $this->postJson(route('admin.guests.scanId', $this->property), ['document' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf')])
        ->assertStatus(422)->assertJsonValidationErrors('document');

    config(['services.anthropic.api_key' => null]);
    $this->postJson(route('admin.guests.scanId', $this->property), ['document' => $image])->assertStatus(503);
});
