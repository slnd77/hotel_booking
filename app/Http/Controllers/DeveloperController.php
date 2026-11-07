<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ServePanelOverApi;
use App\Mail\ApiAccessChangedMail;
use App\Models\User;
use App\Services\ApiScopes;
use App\Services\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * Profile Settings → Developer: API keys for third-party apps, each limited to the scopes
 * chosen (from those the user's role allows) and, on every request, to the user's role; and the
 * mobile devices signed in. A new key's secret is shown once.
 */
class DeveloperController extends Controller
{
    public const EXPIRY_DAYS = [30, 90, 365];

    public function index(Request $request): Response
    {
        $user = $this->developer($request);
        $tokens = $user->tokens()->latest()->get();

        return Inertia::render('profile/Developer', [
            'keys' => $tokens->filter(fn (PersonalAccessToken $token): bool => ApiScopes::isKey($token))->map(fn (PersonalAccessToken $token): array => [
                ...$this->tokenRow($token, ApiScopes::KEY_PREFIX),
                'scopes' => $token->abilities,
                'expires_at' => $token->expires_at?->toIso8601String(),
                'expired' => $token->expires_at?->isPast() ?? false,
            ])->values(),
            'devices' => $tokens->filter(fn (PersonalAccessToken $token): bool => ApiScopes::isDevice($token))->map(fn (PersonalAccessToken $token): array => $this->tokenRow($token, ApiScopes::DEVICE_PREFIX))->values(),
            'scopes' => ApiScopes::availableTo($user),
            'expiryDays' => self::EXPIRY_DAYS,
            // The new key's secret, flashed once after it is made.
            'newKey' => $request->session()->get('api_key'),
            'apiBaseUrl' => url('/api/v1'),
            'specUrl' => asset('openapi.yaml'),
            'endpoints' => $this->endpoints(),
        ]);
    }

    /**
     * The API reference: each `/api/v1` route with its scope, read from the routes themselves.
     *
     * @return list<array{method: string, uri: string, scope: string|null}>
     */
    private function endpoints(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route): bool => Str::startsWith((string) $route->getName(), 'api.v1.') && ! Str::startsWith((string) $route->getName(), ServePanelOverApi::ROUTE_PREFIX))
            ->map(function (RoutingRoute $route): array {
                $scope = collect($route->gatherMiddleware())->first(fn (mixed $middleware): bool => is_string($middleware) && Str::startsWith($middleware, 'api.scope:'));

                return [
                    'method' => collect($route->methods())->reject(fn (string $method): bool => $method === 'HEAD')->implode('|'),
                    'uri' => '/'.$route->uri(),
                    'scope' => $scope ? Str::after($scope, 'api.scope:') : null,
                ];
            })
            ->sortBy('uri')
            ->values()
            ->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->developer($request);
        $allowed = array_column(ApiScopes::availableTo($user), 'value');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'distinct', Rule::in($allowed)],
            'expires_in_days' => ['nullable', 'integer', Rule::in(self::EXPIRY_DAYS)],
        ], ['scopes.*.in' => 'You can only give a key what your role allows.']);

        $expiresAt = isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null;
        $token = $user->createToken(ApiScopes::KEY_PREFIX.trim($data['name']), array_values($data['scopes']), $expiresAt);

        $this->notify($user, "An API key named \"{$data['name']}\" was created with the scopes: ".implode(', ', $data['scopes']).'.');

        return redirect()->route('profile.developer.index')
            ->with('api_key', ['name' => $data['name'], 'token' => $token->plainTextToken])
            ->with('success', 'API key created. Copy it now: it won\'t be shown again.');
    }

    public function destroyKey(Request $request, int $token): RedirectResponse
    {
        $user = $this->developer($request);
        $key = $this->ownToken($user, $token, ApiScopes::KEY_PREFIX);
        $key->delete();

        $name = Str::after($key->name, ApiScopes::KEY_PREFIX);
        $this->notify($user, "The API key named \"{$name}\" was revoked.");

        return back()->with('success', "API key \"{$name}\" revoked.");
    }

    public function destroyDevice(Request $request, int $token): RedirectResponse
    {
        $user = $this->developer($request);
        $device = $this->ownToken($user, $token, ApiScopes::DEVICE_PREFIX);
        $device->delete();

        $name = Str::after($device->name, ApiScopes::DEVICE_PREFIX);
        $this->notify($user, "The device \"{$name}\" was signed out of the mobile app.");

        return back()->with('success', "{$name} signed out.");
    }

    private function developer(Request $request): User
    {
        $user = $request->user();
        abort_unless(PermissionCatalog::canManageApiKeys($user), 403, 'Your role can\'t make API keys.');

        return $user;
    }

    private function ownToken(User $user, int $id, string $prefix): PersonalAccessToken
    {
        $token = $user->tokens()->whereKey($id)->first();
        abort_unless($token instanceof PersonalAccessToken && Str::startsWith($token->name, $prefix), 404);

        return $token;
    }

    /**
     * @return array{id: int, name: string, created_at: string|null, last_used_at: string|null}
     */
    private function tokenRow(PersonalAccessToken $token, string $prefix): array
    {
        return [
            'id' => $token->id,
            'name' => Str::after($token->name, $prefix),
            'created_at' => $token->created_at?->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
        ];
    }

    /**
     * A security notice by e-mail; a mail failure is logged, never blocks the change.
     */
    private function notify(User $user, string $change): void
    {
        Log::info("API access changed for user {$user->id}: {$change}");

        try {
            Mail::to($user->email)->send(new ApiAccessChangedMail($user->name, $change));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
