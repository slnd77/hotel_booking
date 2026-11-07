<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Behind a load balancer or reverse proxy, HTTPS and the visitor's IP come from its
    | X-Forwarded-* headers. "*" trusts the proxy that calls the app; otherwise list its
    | addresses, comma-separated. Empty trusts none. Laravel's TrustProxies middleware reads it.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
