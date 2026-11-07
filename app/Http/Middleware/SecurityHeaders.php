<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening headers on every page. HSTS only over HTTPS in production (browsers then
 * refuse plain HTTP for a year). Framing is limited to this site (clickjacking), except the
 * guest pages hotels embed in their own websites: booking engine, legal pages, web check-in,
 * payment links, QR ordering and menus.
 */
class SecurityHeaders
{
    /** @var list<string> */
    private const EMBEDDABLE = ['properties/*', 'checkin/*', 'pay/*', 'order/*', 'menu/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Web check-in may use the phone camera for the ID photo; nothing needs the microphone or location.
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');

        if (! $request->is(...self::EMBEDDABLE)) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        }

        if ($request->isSecure() && app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
