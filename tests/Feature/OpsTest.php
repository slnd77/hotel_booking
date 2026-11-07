<?php

use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Services\Ops\HealthChecks;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;

beforeEach(function () {
    // No disk alerts from the machine running the tests.
    config(['ops.health_token' => 'secret-token', 'ops.alert_email' => 'ops@example.com', 'mail.default' => 'array', 'ops.thresholds.disk_free_percent' => 0]);
});

/**
 * The e-mails the array mailer has taken.
 *
 * @return list<string> their subjects
 */
function sentSubjects(): array
{
    return collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($message): string => $message->getOriginalMessage()->getSubject())->values()->all();
}

test('health answers 503 until the scheduler has run, and shows its checks only with the token', function () {
    Cache::forget(HealthChecks::HEARTBEAT_KEY);

    $this->getJson(route('health'))->assertStatus(503)->assertExactJson(['status' => 'failing']);

    $this->artisan('ops:monitor')->assertSuccessful();

    $this->getJson(route('health'))->assertOk()->assertExactJson(['status' => 'ok']);
    $this->getJson(route('health'), ['X-Health-Token' => 'secret-token'])
        ->assertOk()
        ->assertJsonPath('checks.database.ok', true)
        ->assertJsonPath('checks.scheduler.ok', true)
        ->assertJsonPath('checks.backup.ok', false)
        ->assertJsonPath('checks.backup.critical', false);
    $this->getJson(route('health').'?token=wrong')->assertJsonMissingPath('checks');
});

test('the monitor alerts once an hour per problem', function () {
    Storage::fake(config('ops.backup.disk'));

    $this->artisan('ops:monitor')->expectsOutputToContain('backup: No database backup yet')->assertSuccessful();
    $this->artisan('ops:monitor')->assertSuccessful();

    expect(sentSubjects())->toBe(['['.config('app.name').'] Warning: backup']);

    $this->travel(61)->minutes();
    $this->artisan('ops:monitor')->assertSuccessful();

    expect(sentSubjects())->toHaveCount(2);
});

test('the backup dumps the database and the uploaded files, gzipped, and drops old backups', function () {
    $disk = Storage::fake('backups');
    Storage::fake('local')->put('web-checkins/1/id.jpg', 'id');
    config([
        'ops.backup.disk' => 'backups',
        'database.connections.production_mysql' => ['driver' => 'mysql', 'host' => 'db', 'port' => 3306, 'database' => 'hotel', 'username' => 'pms', 'password' => 'pw'],
        'filesystems.disks.local.root' => Storage::disk('local')->path(''),
    ]);
    $disk->put('backups/2020-01-01-000000-mysql.sql.gz', 'old');
    touch($disk->path('backups/2020-01-01-000000-mysql.sql.gz'), now()->subDays(30)->timestamp);

    // mysqldump writes its --result-file; the password goes in the environment, not the command.
    Process::fake(function (PendingProcess $process) {
        preg_match('/--result-file=(\S+)/', implode(' ', (array) $process->command), $match);
        file_put_contents($match[1], "CREATE TABLE bookings (id int);\n");

        return Process::result();
    });

    $this->artisan('ops:backup', ['--connection' => 'production_mysql'])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command[0] === 'mysqldump'
        && in_array('--single-transaction', $process->command, true)
        && ! str_contains(implode(' ', $process->command), 'pw')
        && $process->environment['MYSQL_PWD'] === 'pw');

    $files = collect($disk->files('backups'));
    $dump = $files->first(fn (string $path): bool => str_ends_with($path, '-production_mysql.sql.gz'));
    $zip = $files->first(fn (string $path): bool => str_ends_with($path, '-files.zip'));

    expect($files)->not->toContain('backups/2020-01-01-000000-mysql.sql.gz')
        ->and(gzdecode($disk->get($dump)))->toBe("CREATE TABLE bookings (id int);\n")
        ->and($zip)->not->toBeNull();

    $archive = new ZipArchive;
    $archive->open($disk->path($zip));
    expect($archive->locateName('web-checkins/1/id.jpg'))->not->toBeFalse();
    $archive->close();
});

test('a failed dump fails the backup', function () {
    Storage::fake('backups');
    config(['ops.backup.disk' => 'backups', 'database.connections.production_mysql' => ['driver' => 'mysql', 'host' => 'db', 'port' => 3306, 'database' => 'hotel', 'username' => 'pms', 'password' => 'pw']]);
    Process::fake(['*' => Process::result(errorOutput: 'Access denied', exitCode: 2)]);

    $this->artisan('ops:backup', ['--connection' => 'production_mysql'])->expectsOutputToContain('Access denied')->assertFailed();
});

test('pages carry the security headers', function () {
    $this->get(route('login'))
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
});

test('the booking engine can be embedded in hotel websites', function () {
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);
    $property = Property::factory()->create();

    $this->get(route('booking-engine.web', $property))
        ->assertHeaderMissing('X-Frame-Options')
        ->assertHeaderMissing('Content-Security-Policy');
});

test('the mail test sends straight away and warns about the log mailer', function () {
    $this->artisan('ops:mail-test', ['to' => 'me@example.com'])->expectsOutputToContain('Sent to me@example.com')->assertSuccessful();
    expect(sentSubjects())->toBe([config('app.name').': test e-mail']);

    config(['mail.default' => 'log']);
    $this->artisan('ops:mail-test', ['to' => 'me@example.com'])->expectsOutputToContain('MAIL_MAILER is "log"')->assertSuccessful();
});

it('checks PDFs can be rendered', function () {
    Pdf::fake();

    $this->artisan('ops:pdf-check')->expectsOutputToContain('PDF rendering works')->assertSuccessful();
    Pdf::assertSee('PDF check');
});

it('fails the PDF check when the configured Chrome is missing', function () {
    config(['laravel-pdf.driver' => 'browsershot', 'laravel-pdf.browsershot.chrome_path' => '/nowhere/chrome-headless-shell']);

    $this->artisan('ops:pdf-check')->expectsOutputToContain('points at a missing file')->assertFailed();
});
