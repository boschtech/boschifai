<?php

/**
 * Router script for PHP's built-in server, used in place of `php artisan serve` in Docker.
 *
 * Confirmed as necessary by a real bug: `artisan serve` doesn't just run `php -S ...
 * public/index.php` — it spawns the dev server with its OWN router script
 * (vendor/laravel/framework/.../resources/server.php) that passes real static files straight
 * through and only falls back to index.php for everything else. Dropping that router entirely
 * (to fix a separate issue — `artisan serve`'s child process not inheriting this container's
 * DB_CONNECTION=mysql override) meant every request, including JS/CSS assets, hit Laravel's
 * catch-all SPA route in routes/web.php and got back the app shell's HTML instead of the actual
 * file — the browser then refused to execute it as a module script, rendering a blank page.
 * This reproduces Laravel's own router.php logic without going through artisan serve's Process
 * wrapper, so static assets are served correctly AND the environment fix still applies.
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($uri !== '/' && file_exists(__DIR__.'/../../public'.$uri)) {
    return false;
}

require_once __DIR__.'/../../public/index.php';
