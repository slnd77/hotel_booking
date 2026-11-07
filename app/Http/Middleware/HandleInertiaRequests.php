<?php

namespace App\Http\Middleware;

use App\Models\Currency;
use App\Models\Property;
use App\Services\ApplicationSettings;
use App\Services\CurrentProperty;
use App\Services\NightAudits;
use App\Services\PermissionCatalog;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user()?->load('roles'),
                // Roles and Permissions: what the signed-in user may do (the super admin: everything).
                'isSuperAdmin' => fn (): bool => (bool) $request->user()?->hasAnyRole(PermissionCatalog::fullAccessRoles()),
                'permissions' => fn (): array => $request->user()?->getAllPermissions()->pluck('name')->values()->all() ?? [],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // Something went through, but a follow-up didn't (e.g. checked out, invoice not e-mailed).
                'warning' => fn () => $request->session()->get('warning'),
            ],
            'guestSettings' => fn () => $request->routeIs('admin.*')
                ? collect(app(ApplicationSettings::class)->get(ApplicationSettings::GUEST))->only(['default_nationality', 'mandatory'])
                : null,
            'currency' => fn () => $request->routeIs('admin.*') ? Currency::forProperty($this->currentProperty($request)) : null,
            // Times are sent in UTC; the UI shows them in this timezone.
            'timezone' => fn () => $request->routeIs('admin.*') ? ($this->currentProperty($request)?->timezoneName() ?? Property::DEFAULT_TIMEZONE) : null,
            // Night audit → Pre-rollover Notification: automatic rollovers coming up shortly.
            'nightAuditNotices' => fn () => $request->routeIs('admin.*')
                ? Property::query()->get()->map(fn (Property $property): ?array => app(NightAudits::class)->upcomingRollover($property))->filter()->values()->all()
                : [],
            // "Set your default list view": the user's saved columns per table.
            'listViews' => fn (): object => (object) ($request->routeIs('admin.*') ? ($request->user()?->list_views ?? []) : []),
            'adminProperties' => fn () => $request->routeIs('admin.*', 'profile.*')
                ? app(ApplicationSettings::class)->orderedProperties()
                : [],
            // The header's property picker, decided here so SSR and the browser render the same one:
            // the page's own property, else the one last used in the session, else the first.
            'currentProperty' => fn (): ?array => $request->routeIs('admin.*', 'profile.*')
                ? app(CurrentProperty::class)->resolve($request)
                : null,
        ];
    }

    /**
     * The property an admin page is about: the {property} route parameter, else the property
     * of a bound record (a city ledger account, an invoice…), else ?property=.
     */
    private function currentProperty(Request $request): ?Property
    {
        return app(CurrentProperty::class)->fromRequest($request);
    }
}
