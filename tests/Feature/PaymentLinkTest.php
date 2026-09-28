<?php

use App\Enums\BookingStatus;
use App\Enums\PaymentLinkStatus;
use App\Mail\PaymentLinkMail;
use App\Models\Currency;
use App\Models\Guest;
use App\Models\Language;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create();
    $this->booking = $this->property->bookings()->create([
        'booking_ref_id' => 'BK-LINKTEST',
        'check_in' => now()->addDays(2)->toDateString(),
        'check_out' => now()->addDays(4)->toDateString(),
        'status' => BookingStatus::Confirmed,
        'total' => 10000,
        'outstanding' => 6000,
    ]);
    $this->booking->guests()->attach(Guest::factory()->create(['property_id' => $this->property->id, 'emails' => ['guest@example.com']])->id);

    config(['services.test_gateway.enabled' => true]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function collectOnlinePayload(array $overrides = []): array
{
    return ['collect_for' => 'Guest', 'amount' => 2500, 'email' => 'guest@example.com', 'mobile' => '+919876543210', 'send' => false, ...$overrides];
}

test('get payment link stores the link and returns a url with only a random token', function () {
    $response = $this->actingAs($this->admin)
        ->postJson(route('admin.bookings.paymentLinks.store', [$this->property, $this->booking]), collectOnlinePayload())
        ->assertOk();

    $link = PaymentLink::sole();

    expect((float) $link->amount)->toBe(2500.0)
        ->and($link->status)->toBe(PaymentLinkStatus::Pending)
        ->and($link->email)->toBe('guest@example.com')
        ->and($link->created_by)->toBe($this->admin->id)
        ->and($link->expires_at->isFuture())->toBeTrue()
        ->and($response->json('payment_link'))->toBe(route('payment-links.show', $link->token))
        ->and($response->json('payment_link'))->not->toContain('2500');
});

test('send payment link e-mails the guest', function () {
    Mail::fake();

    $this->actingAs($this->admin)
        ->postJson(route('admin.bookings.paymentLinks.store', [$this->property, $this->booking]), collectOnlinePayload(['send' => true]))
        ->assertOk()
        ->assertJson(['message' => 'Payment link sent to guest@example.com.']);

    Mail::assertQueued(PaymentLinkMail::class, fn (PaymentLinkMail $mail): bool => $mail->hasTo('guest@example.com'));
    expect(PaymentLink::sole()->sent_at)->not->toBeNull();
});

test('a link needs an amount within the outstanding balance, and an e-mail to be sent', function () {
    $url = route('admin.bookings.paymentLinks.store', [$this->property, $this->booking]);

    $this->actingAs($this->admin)->postJson($url, collectOnlinePayload(['amount' => 6000.01]))->assertJsonValidationErrors('amount');
    $this->actingAs($this->admin)->postJson($url, collectOnlinePayload(['email' => null, 'send' => true]))->assertJsonValidationErrors('email');

    expect(PaymentLink::count())->toBe(0);
});

test('the guest can pay a link, which records a receipt and lowers the outstanding amount', function () {
    $link = PaymentLink::factory()->create(['property_id' => $this->property->id, 'booking_id' => $this->booking->id, 'amount' => 2500]);

    $this->get(route('payment-links.show', $link->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('PaymentLink/Pay')
            ->where('link.amount', 2500)
            ->where('link.status', 'pending')
            ->where('booking.booking_ref_id', 'BK-LINKTEST')
            ->where('gateways', fn ($gateways): bool => collect($gateways)->contains('id', 'test')));

    $order = $this->postJson(route('payment-links.initiate', $link->token), ['payment_gateway' => 'test'])->assertOk()->json();

    $this->postJson(route('payment-links.confirm', $link->token), ['payment_gateway' => 'test', 'transaction_id' => 'pay_test_1', 'order_id' => $order['order_id']])
        ->assertOk()
        ->assertJson(['success' => true]);

    $link->refresh();
    $payment = Payment::query()->where('collection_source', 'Payment Link')->sole();

    expect($link->status)->toBe(PaymentLinkStatus::Paid)
        ->and($link->payment_id)->toBe($payment->id)
        // Listed once on the booking page, not twice.
        ->and($this->booking->payments()->whereKey($payment->id)->count())->toBe(1)
        ->and((float) $payment->total_txn_amount)->toBe(2500.0)
        ->and((float) $this->booking->fresh()->outstanding)->toBe(3500.0);

    // Confirming again doesn't record the payment twice.
    $this->postJson(route('payment-links.confirm', $link->token), ['payment_gateway' => 'test', 'transaction_id' => 'pay_test_1', 'order_id' => $order['order_id']])->assertOk();
    expect(Payment::query()->where('collection_source', 'Payment Link')->count())->toBe(1)
        ->and((float) $this->booking->fresh()->outstanding)->toBe(3500.0);
});

test('expired links cannot be paid, and a payment must match the started order', function () {
    $expired = PaymentLink::factory()->expired()->create(['property_id' => $this->property->id, 'booking_id' => $this->booking->id]);

    $this->get(route('payment-links.show', $expired->token))->assertInertia(fn ($page) => $page->where('link.status', 'expired')->where('gateways', []));
    $this->postJson(route('payment-links.initiate', $expired->token), ['payment_gateway' => 'test'])->assertJsonValidationErrors('payment_gateway');

    $link = PaymentLink::factory()->create(['property_id' => $this->property->id, 'booking_id' => $this->booking->id]);
    $this->postJson(route('payment-links.initiate', $link->token), ['payment_gateway' => 'test'])->assertOk();

    $this->postJson(route('payment-links.confirm', $link->token), ['payment_gateway' => 'test', 'order_id' => 'someone-elses-order'])
        ->assertJsonValidationErrors('payment_gateway');

    expect($link->fresh()->status)->toBe(PaymentLinkStatus::Pending)
        ->and(Payment::query()->where('collection_source', 'Payment Link')->count())->toBe(0);
});

test('an unknown token is not found', function () {
    $this->get('/pay/not-a-real-token')->assertNotFound();
});

test('a link only works while the booking still owes at least its amount', function () {
    $link = PaymentLink::factory()->create(['property_id' => $this->property->id, 'booking_id' => $this->booking->id, 'amount' => 2500]);

    // Settled some other way (e.g. paid at the desk): nothing left to collect.
    $this->booking->updateQuietly(['outstanding' => 0]);

    $this->get(route('payment-links.show', $link->token))
        ->assertInertia(fn ($page) => $page
            ->where('link.unpayable_reason', 'There is no outstanding amount left to pay for this booking.')
            ->where('gateways', []));
    $this->postJson(route('payment-links.initiate', $link->token), ['payment_gateway' => 'test'])->assertJsonValidationErrors('payment_gateway');

    // Partly settled: the link would now overpay.
    $this->booking->updateQuietly(['outstanding' => 1000]);
    $this->postJson(route('payment-links.initiate', $link->token), ['payment_gateway' => 'test'])->assertJsonValidationErrors('payment_gateway');

    $this->booking->updateQuietly(['outstanding' => 2500]);
    $this->postJson(route('payment-links.initiate', $link->token), ['payment_gateway' => 'test'])->assertOk();
});

test('the booking page lists its payment links, which staff can cancel or e-mail again', function () {
    Mail::fake();

    $link = PaymentLink::factory()->create(['property_id' => $this->property->id, 'booking_id' => $this->booking->id, 'amount' => 2500, 'email' => 'guest@example.com']);

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.show', [$this->property, $this->booking]))
        ->assertInertia(fn ($page) => $page
            ->where('booking.payment_links.0.id', $link->id)
            ->where('booking.payment_links.0.status', 'pending')
            ->where('booking.payment_links.0.url', route('payment-links.show', $link->token))
            ->where('booking.payment_links.0.unpayable_reason', null));

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.paymentLinks.resend', [$this->property, $this->booking, $link]))
        ->assertSessionHas('success', 'Payment link sent to guest@example.com.');
    Mail::assertQueued(PaymentLinkMail::class);

    $this->actingAs($this->admin)
        ->patch(route('admin.bookings.paymentLinks.cancel', [$this->property, $this->booking, $link]))
        ->assertSessionHas('success', 'Payment link cancelled.');

    expect($link->fresh()->status)->toBe(PaymentLinkStatus::Cancelled);
    $this->postJson(route('payment-links.initiate', $link->token), ['payment_gateway' => 'test'])->assertJsonValidationErrors('payment_gateway');
});
