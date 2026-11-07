<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\PushChannexAri;
use App\Models\ActivityLog;
use App\Models\ChannexBookingRevision;
use App\Models\ChannexConnection;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Services\Channex\ChannexBookings;
use App\Services\Channex\ChannexClient;
use App\Services\Channex\ChannexException;
use App\Services\Channex\ChannexSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Channel Manager: links a property to Channex, maps room categories and rate
 * rows to Channex room types and rate plans, syncs availability and rates out and OTA
 * bookings in, and opens Channex's screen for connecting OTAs (Booking.com, Airbnb, …).
 */
class ChannelManagerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/channel-manager/Index');
    }

    public function edit(Property $property, ChannexSetup $setup): Response
    {
        $connection = ChannexConnection::query()->where('property_id', $property->id)->first();
        $remote = ['properties' => [], 'room_types' => [], 'rate_plans' => []];
        $remoteError = null;

        if (ChannexClient::isConfigured($connection)) {
            try {
                $client = ChannexClient::for($connection);

                if ($connection) {
                    $remote['room_types'] = $client->roomTypes($connection->channex_property_id);
                    $remote['rate_plans'] = $client->ratePlans($connection->channex_property_id);
                } else {
                    $remote['properties'] = $client->properties();
                }
            } catch (ChannexException $exception) {
                $remoteError = $exception->getMessage();
            }
        }

        $categories = RoomCategory::query()->where('property_id', $property->id)->orderBy('display_order')->get(['id', 'name', 'max_adults', 'meal_plans']);

        return Inertia::render('admin/channel-manager/Edit', [
            'property' => $property->only(['id', 'name']),
            'apiKeyConfigured' => ChannexClient::isConfigured($connection),
            'connection' => $connection ? [
                'id' => $connection->id,
                'channex_property_id' => $connection->channex_property_id,
                'is_active' => $connection->is_active,
                'sync_days' => $connection->sync_days,
                'has_own_key' => filled($connection->api_key),
                'webhook_registered' => filled($connection->channex_webhook_id),
                'last_pushed_at' => $connection->last_pushed_at?->toIso8601ZuluString(),
                'last_pulled_at' => $connection->last_pulled_at?->toIso8601ZuluString(),
                'last_error' => $connection->last_error,
            ] : null,
            'remote' => $remote,
            'remoteError' => $remoteError,
            'categories' => $categories->map(fn (RoomCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'max_adults' => max(1, (int) $category->max_adults),
                'meal_plans' => $setup->mealPlans($category),
            ])->values(),
            'roomMappings' => $connection?->roomMappings()->get(['room_category_id', 'channex_room_type_id']) ?? [],
            'rateMappings' => $connection?->rateMappings()->get(['room_category_id', 'meal_plan', 'adults', 'channex_rate_plan_id']) ?? [],
            'revisions' => $connection?->revisions()->with('booking:id,booking_ref_id')->latest('id')->limit(25)->get()
                ->map(fn (ChannexBookingRevision $revision): array => [
                    'id' => $revision->id,
                    'status' => $revision->status,
                    'ota_name' => $revision->ota_name,
                    'ota_reservation_code' => $revision->ota_reservation_code,
                    'arrival' => $revision->payload['arrival_date'] ?? null,
                    'departure' => $revision->payload['departure_date'] ?? null,
                    'amount' => $revision->payload['amount'] ?? null,
                    'currency' => $revision->payload['currency'] ?? null,
                    'booking' => $revision->booking ? ['id' => $revision->booking->id, 'booking_ref_id' => $revision->booking->booking_ref_id] : null,
                    'processed_at' => $revision->processed_at?->toIso8601ZuluString(),
                    'error' => $revision->error,
                    'received_at' => $revision->created_at?->toIso8601ZuluString(),
                ]) ?? [],
            'webhookUrlIsPublic' => ChannexSetup::isPublicUrl(route('api.channex.webhook', ['token' => 'x'])),
        ]);
    }

    public function connect(Request $request, Property $property, ChannexSetup $setup): RedirectResponse
    {
        abort_if(ChannexConnection::query()->where('property_id', $property->id)->exists(), 409, 'This property is already connected.');

        $data = $request->validate([
            'mode' => ['required', Rule::in(['existing', 'create'])],
            'channex_property_id' => ['required_if:mode,existing', 'nullable', 'uuid'],
            'api_key' => ['nullable', 'string', 'max:255'],
        ]);

        if (! filled($data['api_key'] ?? null) && ! ChannexClient::isConfigured()) {
            return back()->with('error', 'Enter a Channex API key, or set CHANNEX_API_KEY.');
        }

        try {
            $connection = $setup->connect($property, $data['mode'] === 'existing' ? $data['channex_property_id'] : null, $data['api_key'] ?? null);
        } catch (ChannexException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->log($request, $property, "Connected to Channex property {$connection->channex_property_id}.");

        return back()->with('success', 'Connected to Channex. Now map the room categories and rates.');
    }

    public function update(Request $request, Property $property): RedirectResponse
    {
        $connection = $this->connection($property);

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            'sync_days' => ['required', 'integer', 'min:30', 'max:730'],
            'api_key' => ['nullable', 'string', 'max:255'],
        ]);

        $connection->fill([
            'is_active' => $data['is_active'],
            'sync_days' => $data['sync_days'],
            ...(filled($data['api_key'] ?? null) ? ['api_key' => $data['api_key']] : []),
        ])->save();

        return back()->with('success', 'Channel manager settings saved.');
    }

    /**
     * Replaces the room and rate mappings, then pushes a full sync.
     */
    public function saveMappings(Request $request, Property $property): RedirectResponse
    {
        $connection = $this->connection($property);
        $categoryIds = RoomCategory::query()->where('property_id', $property->id)->pluck('id')->all();

        $data = $request->validate([
            'rooms' => ['array'],
            'rooms.*.room_category_id' => ['required', 'integer', Rule::in($categoryIds)],
            'rooms.*.channex_room_type_id' => ['nullable', 'uuid', 'distinct'],
            'rates' => ['array'],
            'rates.*.room_category_id' => ['required', 'integer', Rule::in($categoryIds)],
            'rates.*.meal_plan' => ['required', 'string', 'max:10'],
            'rates.*.adults' => ['required', 'integer', 'min:1', 'max:20'],
            'rates.*.channex_rate_plan_id' => ['nullable', 'uuid', 'distinct'],
        ]);

        DB::transaction(function () use ($connection, $data): void {
            $connection->roomMappings()->delete();
            $connection->rateMappings()->delete();

            foreach ($data['rooms'] ?? [] as $row) {
                if (filled($row['channex_room_type_id'] ?? null)) {
                    $connection->roomMappings()->create($row);
                }
            }

            foreach ($data['rates'] ?? [] as $row) {
                if (filled($row['channex_rate_plan_id'] ?? null)) {
                    $connection->rateMappings()->create($row);
                }
            }
        });

        PushChannexAri::dispatch($connection->id);
        $this->log($request, $property, 'Channex room and rate mappings saved.');

        return back()->with('success', 'Mappings saved; a full sync to Channex is on its way.');
    }

    public function createRoomsAndRates(Request $request, Property $property, ChannexSetup $setup): RedirectResponse
    {
        $connection = $this->connection($property);

        try {
            $made = $setup->createRoomsAndRates($connection);
        } catch (ChannexException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        PushChannexAri::dispatch($connection->id);
        $this->log($request, $property, "Created {$made['room_types']} room type(s) and {$made['rate_plans']} rate plan(s) in Channex.");

        return back()->with('success', "Created {$made['room_types']} room type(s) and {$made['rate_plans']} rate plan(s) in Channex, and mapped them.");
    }

    public function sync(Property $property): RedirectResponse
    {
        PushChannexAri::dispatch($this->connection($property)->id);

        return back()->with('success', 'Full availability and rate sync queued.');
    }

    public function pull(Property $property, ChannexBookings $bookings): RedirectResponse
    {
        try {
            $result = $bookings->pull($this->connection($property));
        } catch (ChannexException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with($result['failed'] > 0 ? 'error' : 'success', "{$result['applied']} booking revision(s) applied".($result['failed'] > 0 ? ", {$result['failed']} failed — see the log below." : '.'));
    }

    /**
     * Opens Channex's own Channels screen (connect Booking.com, Airbnb, …) for this property,
     * signed in with a one-time token.
     */
    public function channels(Request $request, Property $property): RedirectResponse
    {
        $connection = $this->connection($property);

        try {
            $token = ChannexClient::for($connection)->oneTimeToken($connection->channex_property_id, (string) $request->user()->email);
        } catch (ChannexException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->away(rtrim((string) config('services.channex.app_url'), '/').'/auth/exchange?'.http_build_query([
            'oauth_session_key' => $token,
            'app_mode' => 'headless',
            'redirect_to' => '/channels',
            'property_id' => $connection->channex_property_id,
        ]));
    }

    public function destroy(Request $request, Property $property, ChannexSetup $setup): RedirectResponse
    {
        $connection = $this->connection($property);
        $setup->disconnect($connection);
        $this->log($request, $property, "Disconnected from Channex property {$connection->channex_property_id}.");

        return back()->with('success', 'Disconnected from Channex. OTA bookings already received stay.');
    }

    private function connection(Property $property): ChannexConnection
    {
        return ChannexConnection::query()->where('property_id', $property->id)->firstOrFail();
    }

    private function log(Request $request, Property $property, string $action): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $property->id,
            'action' => "Channel manager: {$action}",
            'date' => now(),
        ]);
    }
}
