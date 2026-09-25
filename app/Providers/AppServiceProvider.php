<?php

namespace App\Providers;

use App\Models\Booking;
use App\Models\BookingPaymentMapping;
use App\Models\CompanyProfile;
use App\Models\Guest;
use App\Models\MetaField;
use App\Models\OutletBill;
use App\Models\Payment;
use App\Models\PropertyMetaField;
use App\Models\RoomBlock;
use App\Models\SubBooking;
use App\Services\ApplicationSettings;
use App\Services\BookingLedgerSync;
use App\Services\Channex\ChannexTriggers;
use App\Services\GuestEmails;
use App\Services\PermissionCatalog;
use App\Services\PropertySettings;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Grammar;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Testing\Fakes\Fake;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per app so repeated writes in a transaction sync a booking once.
        $this->app->singleton(BookingLedgerSync::class);

        // Per request / queued job: property settings are read once and reused (PropertySettings).
        $this->app->scoped(PropertySettings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureNotificationMailer();
        $this->configureMorphMap();
        $this->syncLedgerOnBookingWrites();
        $this->pushChannexOnInventoryWrites();
        $this->emailGuestsOnBookingWrites();
        $this->registerQueryMacros();
        $this->forgetSettingsOnWrite();
        $this->configureApiRateLimits();

        // Super admin can do anything: every Gate / can() check passes, whatever the matrix says.
        Gate::before(fn ($user) => $user->hasRole(PermissionCatalog::SUPER_ADMIN) ? true : null);
    }

    /**
     * API: each token (device or API key) gets its own budget; sign-in steps are limited per
     * e-mail / challenge and address.
     */
    protected function configureApiRateLimits(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            // A browser session (no API token) is limited by its address.
            $token = $request->user()?->currentAccessToken();

            return Limit::perMinute(120)->by($token instanceof PersonalAccessToken ? 'token:'.$token->getKey() : 'token:'.$request->ip());
        });
        RateLimiter::for('api-auth', fn (Request $request) => Limit::perMinute(10)->by(strtolower((string) ($request->input('email') ?? $request->input('challenge_token'))).'|'.$request->ip()));

        // Booking engine: separate budgets for pricing and checkout, per visitor and property.
        foreach (['quote', 'checkout'] as $step) {
            RateLimiter::for("booking-engine-{$step}", fn (Request $request) => Limit::perMinute(max(1, (int) config("services.booking_engine.{$step}_per_minute")))
                ->by("booking-engine-{$step}|".$request->route()?->originalParameter('property').'|'.$request->ip()));
        }
    }

    /**
     * A saved or deleted setting is read fresh next time (PropertySettings caches per request).
     */
    protected function forgetSettingsOnWrite(): void
    {
        $forgetProperty = fn (PropertyMetaField $setting) => app(PropertySettings::class)->forget((int) $setting->property_id);
        PropertyMetaField::saved($forgetProperty);
        PropertyMetaField::deleted($forgetProperty);

        $forgetFields = fn () => app(PropertySettings::class)->forgetMetaFields();
        MetaField::saved($forgetFields);
        MetaField::deleted($forgetFields);
    }

    /**
     * whereLikeText / orWhereLikeText: a case-insensitive "contains" on a JSON column (e.g. a
     * guest's e-mails). PostgreSQL can't LIKE a json value, so it is cast to text there.
     */
    protected function registerQueryMacros(): void
    {
        Builder::macro('whereLikeText', function (string $column, string $value, string $boolean = 'and'): Builder {
            if ($this->getConnection() instanceof PostgresConnection) {
                // The column, quoted by the grammar, cast to text.
                $asText = new class($column) implements ExpressionContract
                {
                    public function __construct(private string $column) {}

                    public function getValue(Grammar $grammar): string
                    {
                        return $grammar->wrap($this->column).'::text';
                    }
                };

                return $this->where($asText, 'ilike', $value, $boolean);
            }

            return $this->whereLike($column, $value, false, $boolean);
        });

        Builder::macro('orWhereLikeText', function (string $column, string $value): Builder {
            return $this->whereLikeText($column, $value, 'or');
        });
    }

    /**
     * Channel manager: anything that changes what a property can sell queues a push of its
     * availability to Channex (see ChannexTriggers; a no-op for unconnected properties).
     */
    protected function pushChannexOnInventoryWrites(): void
    {
        $triggers = fn (): ChannexTriggers => $this->app->make(ChannexTriggers::class);

        Booking::saved(fn (Booking $booking) => $triggers()->bookingChanged($booking));
        SubBooking::saved(fn (SubBooking $stay) => $triggers()->stayChanged($stay));
        SubBooking::deleted(fn (SubBooking $stay) => $triggers()->stayChanged($stay));
        RoomBlock::saved(fn (RoomBlock $block) => $triggers()->blockChanged($block));
        RoomBlock::deleted(fn (RoomBlock $block) => $triggers()->blockChanged($block));
    }

    /**
     * Booking confirmation, cancellation and payment receipt e-mails to the guest (queued).
     */
    protected function emailGuestsOnBookingWrites(): void
    {
        Booking::saved(fn (Booking $booking) => $this->app->make(GuestEmails::class)->bookingSaved($booking));
        Payment::created(fn (Payment $payment) => $this->app->make(GuestEmails::class)->paymentCreated($payment));
    }

    /**
     * The booking's money still lives on the booking (rates, discount, taxes, total) and in
     * receipts, so every write to a booking, its rooms or its receipts re-syncs the folio
     * ledger (see BookingLedgerSync).
     */
    protected function syncLedgerOnBookingWrites(): void
    {
        $sync = fn (?int $bookingId) => $this->app->make(BookingLedgerSync::class)->schedule($bookingId);

        Booking::saved(fn (Booking $booking) => $sync($booking->id));
        SubBooking::saved(fn (SubBooking $room) => $sync($room->booking_id));
        // A removed room's folio moves to the booking so its entries stay in the balance.
        SubBooking::deleting(fn (SubBooking $room) => $this->app->make(BookingLedgerSync::class)->releaseRoomFolios($room));
        SubBooking::deleted(fn (SubBooking $room) => $sync($room->booking_id));
        BookingPaymentMapping::saved(fn (BookingPaymentMapping $mapping) => $sync($mapping->booking_id));
        BookingPaymentMapping::deleted(fn (BookingPaymentMapping $mapping) => $sync($mapping->booking_id));

        $syncPaymentBookings = function (Payment $payment) use ($sync): void {
            $bookingIds = BookingPaymentMapping::query()->where('payment_id', $payment->id)->pluck('booking_id')->push($payment->booking_id);
            $bookingIds->filter()->unique()->each(fn (int $bookingId) => $sync($bookingId));
        };

        Payment::saved($syncPaymentBookings);
        Payment::deleted($syncPaymentBookings);
    }

    /**
     * Stable names in polymorphic columns (folios.owner_type, sub_folios.payer_type)
     * instead of class names, so models can be renamed without rewriting data.
     */
    protected function configureMorphMap(): void
    {
        Relation::morphMap([
            'booking' => Booking::class,
            'sub_booking' => SubBooking::class,
            'company_profile' => CompanyProfile::class,
            'guest' => Guest::class,
            'outlet_bill' => OutletBill::class,
        ]);
    }

    /**
     * Applies Application Settings → Notification Settings → E-mail the first time mail is used.
     */
    protected function configureNotificationMailer(): void
    {
        $this->app->afterResolving('mail.manager', function (): void {
            try {
                $this->app->make(ApplicationSettings::class)->applyEmailChannelToMailConfig();
            } catch (QueryException) {
                // Settings table not migrated yet: keep the .env mail settings.
            }
        });

        // A queue worker reads the settings afresh for each job (they may have changed, or the
        // job belongs to another account), when it first sends mail.
        // (A test's Mail::fake() / Notification::fake() stays in place.)
        Event::listen(JobProcessing::class, function (): void {
            if ($this->app->resolved('mail.manager') && ! $this->app->make('mail.manager') instanceof Fake) {
                $this->app->forgetInstance('mail.manager');
                $this->app->forgetInstance('mailer');
                Mail::clearResolvedInstance('mail.manager');
            }

            if ($this->app->resolved(ChannelManager::class) && ! $this->app->make(ChannelManager::class) instanceof Fake) {
                $this->app->forgetInstance(ChannelManager::class);
                Notification::clearResolvedInstance(ChannelManager::class);
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Links, redirects and signed URLs use https when the site is served over it.
        URL::forceHttps(app()->isProduction() && str_starts_with((string) config('app.url'), 'https://'));

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
