<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\ChargeCode;
use App\Models\CreditNote;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PropertySettings;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);

    $this->property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    $room = Room::factory()->create(['room_category_id' => $category->id]);

    // Five nights at 1 000 plus 18% tax, checked out and not yet paid.
    $this->booking = DB::transaction(function () use ($category, $room): Booking {
        $booking = $this->property->bookings()->create([
            'booking_ref_id' => 'CN-BOOKING', 'source' => 'direct', 'status' => BookingStatus::CheckedOut,
            'check_in' => now()->subDays(5)->toDateString(), 'check_out' => now()->toDateString(),
            'room_total' => 5000, 'discount' => 0, 'taxes' => 900, 'total' => 5900, 'outstanding' => 5900,
            'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        ]);
        $booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => $room->id, 'meal_plan' => 'EP', 'adults' => 1, 'rate_per_night' => 1000]);

        return $booking;
    });
    $this->invoice = Invoice::generateForBooking($this->booking->refresh(), $this->admin->name);
});

test('a credit note credits part of an invoice, splits out its tax and lowers what the booking owes', function () {
    $this->post(route('admin.databases.invoices.creditNotes.store', $this->invoice), ['total' => 1180, 'reason' => 'One night charged twice'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $note = CreditNote::query()->sole();

    expect($note->credit_note_number)->toBe('CN-0001')
        ->and((float) $note->taxable_amount)->toBe(1000.0)
        ->and((float) $note->tax_amount)->toBe(180.0)
        ->and($note->booking_id)->toBe($this->booking->id)
        ->and($note->transaction->charge_code_id)->toBe(ChargeCode::system(ChargeCode::CREDIT_NOTE)->id)
        ->and((float) $note->transaction->amount)->toBe(1180.0)
        ->and((float) $this->booking->refresh()->outstanding)->toBe(4720.0)
        ->and((float) $this->invoice->refresh()->invoice_outstanding_amount)->toBe(4720.0);

    // The ledger sync leaves the credit where it is.
    expect(Transaction::query()->where('charge_code_id', ChargeCode::system(ChargeCode::CREDIT_NOTE)->id)->count())->toBe(1);

    // Only what is left on the invoice can be credited.
    $this->post(route('admin.databases.invoices.creditNotes.store', $this->invoice), ['total' => 4720.01, 'reason' => 'Too much'])
        ->assertSessionHasErrors(['total' => 'At most 4,720.00 can be credited on this invoice.']);
    expect(CreditNote::query()->count())->toBe(1);
});

test('credit notes follow the tracker numbers and are listed, printed and downloaded', function () {
    $field = MetaField::query()->firstOrCreate(['key' => 'invoice_settings_trackers'], ['key_label' => 'Trackers', 'status' => true]);
    $this->property->metaFieldValues()->create(['meta_fields_key_id' => $field->id, 'enabled' => true, 'data' => json_encode(['credit_note' => ['prefix' => 'CR/', 'in_prefix' => '26-27/', 'postfix' => '']])]);
    app(PropertySettings::class)->forgetMetaFields();

    $this->post(route('admin.databases.invoices.creditNotes.store', $this->invoice), ['total' => 590, 'reason' => 'Late check-in goodwill']);
    $note = CreditNote::query()->sole();

    expect($note->credit_note_number)->toBe('CR/26-27/0001');

    $this->get(route('admin.databases.invoices.index', ['property' => $this->property->id, 'tab' => 'credit_note']))
        ->assertInertia(fn ($page) => $page
            ->where('creditNotes.data.0.credit_note_number', 'CR/26-27/0001')
            ->where('creditNotes.data.0.invoice_number', $this->invoice->invoice_number));
    $this->get(route('admin.databases.invoices.index', ['property' => $this->property->id]))
        ->assertInertia(fn ($page) => $page->where('invoices.data.0.credited_total', 590));

    $this->get(route('admin.databases.invoices.creditNotes.print', $note))
        ->assertOk()
        ->assertSee('CR/26-27/0001')
        ->assertSee($this->invoice->invoice_number)
        ->assertSee('Late check-in goodwill');
});

test('issuing a credit note needs its own permission', function () {
    $clerk = User::factory()->create();
    $clerk->assignRole(Role::findOrCreate('Clerk', 'web'));
    $clerk->givePermissionTo(Permission::findOrCreate('database.invoices.view_download', 'web'));

    $this->actingAs($clerk)->post(route('admin.databases.invoices.creditNotes.store', $this->invoice), ['total' => 100, 'reason' => 'Test'])->assertForbidden();

    $clerk->givePermissionTo(Permission::findOrCreate('database.invoices.issue_credit_note', 'web'));
    $this->actingAs($clerk)->post(route('admin.databases.invoices.creditNotes.store', $this->invoice), ['total' => 100, 'reason' => 'Test'])->assertSessionHasNoErrors();
});
