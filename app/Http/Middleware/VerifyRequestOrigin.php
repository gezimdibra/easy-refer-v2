<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Origin/Referer verification on sensitive endpoints (spec Section
 * 8.4), as defense in depth behind the CSRF token: when the browser
 * sends an Origin (or, failing that, a Referer), its host must be
 * this application's own host. Requests with neither header are
 * non-browser calls and pass; browsers always send one of them on
 * state-changing requests.
 */
class VerifyRequestOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        // Attached only to state-changing routes (routes/web.php), so
        // every request reaching this middleware is one to verify.
        $origin = $request->headers->get('Origin') ?? $request->headers->get('Referer');

        if ($origin !== null) {
            $host = parse_url($origin, PHP_URL_HOST);
            $port = parse_url($origin, PHP_URL_PORT);

            $matches = is_string($host)
                && strcasecmp($host, $request->getHost()) === 0
                && ($port === null || (int) $port === $request->getPort());

            if (! $matches) {
                abort(403);
            }
        }

        return $next($request);
    }
}
