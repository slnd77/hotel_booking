<?php

use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\User;
use App\Services\AadhaarSecureQr;
use App\Services\PassportMrz;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

/** The ICAO 9303 specimen passport's machine-readable zone. */
const SPECIMEN_MRZ = ['P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<', 'L898902C36UTO7408122F1204159ZE184226B<<<<<10'];

/**
 * An Aadhaar Secure QR code's text, built the way UIDAI builds it: the fields joined by byte
 * 255 (after the version), then the photo and signature, gzip-compressed, as a base-10 number.
 *
 * @param  array<string, string>  $overrides
 */
function aadhaarQr(array $overrides = []): string
{
    $fields = [
        'indicator' => '3', 'reference' => '901220190625103512345', 'name' => 'Asha Kumari Rao', 'dob' => '12-04-1990', 'gender' => 'F',
        'care_of' => 'D/O Ravi Rao', 'district' => 'Bengaluru Urban', 'landmark' => 'Near Cubbon Park', 'house' => '12', 'location' => 'Shivajinagar',
        'pincode' => '560001', 'post_office' => 'Bengaluru GPO', 'state' => 'Karnataka', 'street' => 'MG Road', 'sub_district' => 'Bengaluru North', 'vtc' => 'Bengaluru',
        ...$overrides,
    ];
    $record = 'V2'."\xFF".implode("\xFF", $fields)."\xFF".'9876'."\xFF".random_bytes(1500).random_bytes(256);
    $bytes = gzencode($record);

    $decimal = '0';
    foreach (str_split($bytes) as $byte) {
        $decimal = bcadd(bcmul($decimal, '256'), (string) ord($byte));
    }

    return $decimal;
}

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);
    $this->property = Property::factory()->create();

    config(['services.anthropic.api_key' => null]);
    Http::preventStrayRequests();
});

it('decodes an Aadhaar Secure QR code', function () {
    $fields = app(AadhaarSecureQr::class)->read(aadhaarQr());

    expect($fields)->toMatchArray([
        'document_type' => 'aadhaar', 'document_number' => 'XXXXXXXX9012', 'first_name' => 'Asha Kumari', 'last_name' => 'Rao',
        'gender' => 'Female', 'date_of_birth' => '1990-04-12', 'nationality' => 'India', 'postal_code' => '560001', 'confidence' => 'high',
        'address' => '12, MG Road, Near Cubbon Park, Shivajinagar, Bengaluru, Bengaluru North, Bengaluru Urban, Karnataka',
    ]);
});

it('reads the ICAO specimen passport, fixing digits OCR reads as letters', function () {
    $reader = app(PassportMrz::class);

    expect($reader->read(...SPECIMEN_MRZ))->toMatchArray([
        'document_type' => 'passport', 'document_number' => 'L898902C3', 'first_name' => 'Anna Maria', 'last_name' => 'Eriksson',
        'gender' => 'Female', 'date_of_birth' => '1974-08-12', 'date_of_expiry' => '2012-04-15', 'nationality' => 'UTO',
    ])
        // An O read for a 0 in the expiry date is put right before the check digits are tested.
        ->and($reader->read(SPECIMEN_MRZ[0], str_replace('1204159', '12O4159', SPECIMEN_MRZ[1]))['date_of_expiry'])->toBe('2012-04-15')
        ->and($reader->find("PASSPORT\nREPUBLIC OF UTOPIA\n".SPECIMEN_MRZ[0]."\n".SPECIMEN_MRZ[1]."\n"))->toBe(SPECIMEN_MRZ);
});

it('reads the zone as tesseract really returns it (filler misread as K, O as 0)', function () {
    $ocr = "PASSPORTREPUBLICOFUTOPIA\nSERIKSSONGANNAMARIA\nP<UTOERIKSSON<<ANNA<MARIA<<<<<<<KKKKKKK<<<<\nL898902C36UT07408122F1204159ZE184226B<<<<<10\n";
    $reader = app(PassportMrz::class);

    expect($reader->read(...$reader->find($ocr)))->toMatchArray([
        'first_name' => 'Anna Maria', 'last_name' => 'Eriksson', 'nationality' => 'UTO', 'document_number' => 'L898902C3', 'date_of_birth' => '1974-08-12',
    ]);
});

it('rejects a machine-readable zone whose check digits do not match', function () {
    app(PassportMrz::class)->read(SPECIMEN_MRZ[0], str_replace('7408122', '7408132', SPECIMEN_MRZ[1]));
})->throws(RuntimeException::class, 'check digit');

it('fills the guest form from an Aadhaar QR code on the server, without AI', function () {
    fakeIdTools(qrCodes: ['https://example.com/not-aadhaar', aadhaarQr()]);

    $this->postJson(route('admin.guests.scanId', $this->property), ['document' => UploadedFile::fake()->image('aadhaar.jpg', 1200, 800)])
        ->assertOk()
        ->assertJsonPath('scan.method', 'aadhaar_qr')
        ->assertJsonPath('scan.document_label', 'Aadhaar')
        ->assertJsonPath('guest.first_name', 'Asha Kumari')
        ->assertJsonPath('guest.dob', '1990-04-12')
        ->assertJsonPath('guest.address.postal_code', '560001');

    expect(ActivityLog::query()->latest('id')->value('action'))->toBe('Guest ID scanned (Aadhaar, Aadhaar Secure QR) via Web');
});

it('fills the passport details from its machine-readable zone on the server', function () {
    fakeIdTools(text: "UTOPIA\n".implode("\n", SPECIMEN_MRZ));

    $this->postJson(route('admin.guests.scanId', $this->property), ['document' => UploadedFile::fake()->image('passport.jpg', 1200, 800)])
        ->assertOk()
        ->assertJsonPath('scan.method', 'passport_mrz')
        ->assertJsonPath('guest.last_name', 'Eriksson')
        ->assertJsonPath('guest.international_details.passport_number', 'L898902C3')
        ->assertJsonPath('guest.international_details.passport_validity', '2012-04-15');
});

it('falls back to AI when the server finds nothing it can read', function () {
    fakeIdTools(text: 'DRIVING LICENCE KA01 20190012345');
    config(['services.anthropic.api_key' => 'sk-ant-test']);
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode([
        'document_type' => 'driving_licence', 'document_number' => 'KA0120190012345', 'first_name' => 'Ravi', 'last_name' => 'Kumar', 'gender' => 'Male',
        'date_of_birth' => '1985-01-02', 'nationality' => 'India', 'address' => null, 'postal_code' => null, 'date_of_issue' => null,
        'date_of_expiry' => null, 'place_of_issue' => null, 'confidence' => 'medium', 'notes' => null,
    ])]]])]);

    $this->postJson(route('admin.guests.scanId', $this->property), ['document' => UploadedFile::fake()->image('licence.jpg')])
        ->assertOk()
        ->assertJsonPath('scan.method', 'ai')
        ->assertJsonPath('guest.first_name', 'Ravi');
});

it('says what to do when nothing is found and AI is not set up, and is off without any reader', function () {
    fakeIdTools(text: 'NOTHING USEFUL');

    $this->postJson(route('admin.guests.scanId', $this->property), ['document' => UploadedFile::fake()->image('blurry.jpg')])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'No Aadhaar QR code or passport'));

    fakeIdTools(installed: false);

    $this->postJson(route('admin.guests.scanId', $this->property), ['document' => UploadedFile::fake()->image('id.jpg')])->assertStatus(503);
});
