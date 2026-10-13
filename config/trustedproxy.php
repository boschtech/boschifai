<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Read by Laravel's own TrustProxies middleware. Behind a TLS-terminating proxy (Caddy in
    | docker-compose.prod.yml) the app only sees plain HTTP; without trusting the proxy's
    | X-Forwarded-* headers, url() builds http:// links — including the GitHub OAuth
    | redirect_uri, which then no longer matches the registered callback URL.
    |
    | `*` trusts whichever address is calling the app, so set it only when nothing but the proxy
    | can reach the app (production compose publishes no port for `app`). A comma-separated list
    | of IPs/CIDRs also works. Unset = trust no proxy (local development).
    */
    'proxies' => env('TRUSTED_PROXIES'),

];
