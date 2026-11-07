<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel for the mobile app (`/api/v1/panel/…`, the same controllers as `/admin/…`):
 * each page answers its Inertia props as JSON, and an action that would redirect with a flash
 * message answers that message instead (422 with the errors when it failed).
 *
 * The request gets a throw-away in-memory session, so flash messages, `back()` and
 * `withErrors()` work as on the web without storing anything.
 */
class ServePanelOverApi
{
    /** The panel's route names through the API start with this instead of `admin.`. */
    public const ROUTE_PREFIX = 'api.v1.panel.';

    /**
     * The web name of a panel route (`api.v1.panel.pos.show` → `admin.pos.show`), so permission
     * and closed-date checks keyed by admin route names apply to both.
     */
    public static function adminRouteName(?string $name): ?string
    {
        return $name !== null && Str::startsWith($name, self::ROUTE_PREFIX)
            ? 'admin.'.Str::after($name, self::ROUTE_PREFIX)
            : $name;
    }

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set(Header::INERTIA, 'true');

        // The token's user, on the web guard the panel's code expects (Spatie's role relations
        // read the model of the default guard, which Sanctum has none of).
        if (($user = $request->user()) !== null) {
            Auth::shouldUse('web');
            Auth::guard('web')->setUser($user);
        }

        $session = new Store('panel', new ArraySessionHandler(1));
        $session->start();
        $request->setLaravelSession($session);
        app()->instance('session.store', $session);
        app('redirect')->setSession($session);

        $response = $next($request);

        if ($response instanceof JsonResponse && $response->headers->get(Header::INERTIA) === 'true') {
            $page = $response->getData(true);

            return response()->json([
                'component' => $page['component'] ?? null,
                'props' => $page['props'] ?? [],
                'flash' => array_filter(['success' => $session->get('success'), 'error' => $session->get('error'), 'warning' => $session->get('warning')]),
            ]);
        }

        if (! $response instanceof RedirectResponse) {
            return $response;
        }

        $errors = $session->get('errors');

        if ($errors instanceof ViewErrorBag && $errors->any()) {
            $messages = $errors->getBag('default')->getMessages();

            return response()->json(['message' => collect($messages)->flatten()->first(), 'errors' => $messages], 422);
        }

        if (filled($session->get('error')) && blank($session->get('success'))) {
            return response()->json(['message' => $session->get('error')], 422);
        }

        // Off to another site (Channex's channel screen): the app opens it itself.
        $target = $response->getTargetUrl();
        $external = parse_url($target, PHP_URL_HOST) !== null && parse_url($target, PHP_URL_HOST) !== $request->getHost();

        return response()->json(array_filter([
            'message' => $session->get('success') ?? $session->get('warning') ?? ($external ? 'Opening…' : 'Saved.'),
            'warning' => filled($session->get('success')) ? $session->get('warning') : null,
            'redirect' => $external ? $target : null,
        ]));
    }
}
