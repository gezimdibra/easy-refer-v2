<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Origin/Referer verification on sensitive endpoints (spec Section
 * 8.4), as defense in depth behind the CSRF token: when the browser
 * sends an Origin (or, failing that, a Referer), its scheme, host,
 * and port must match the application's configured APP_URL origin.
 * The comparison target is the configured URL, never the request's
 * own Host header, so a poisoned Host cannot satisfy the check.
 * Requests with neither header are non-browser calls and pass;
 * browsers always send one of them on state-changing requests.
 */
class VerifyRequestOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        // Attached only to state-changing routes (routes/web.php), so
        // every request reaching this middleware is one to verify.
        $origin = $request->headers->get('Origin') ?? $request->headers->get('Referer');

        if ($origin !== null && ! $this->matchesAppOrigin($origin)) {
            abort(403);
        }

        return $next($request);
    }

    private function matchesAppOrigin(string $origin): bool
    {
        $expected = parse_url((string) config('app.url'));
        $actual = parse_url($origin);

        if (! is_array($expected) || ! is_array($actual)) {
            return false;
        }

        $expectedHost = $expected['host'] ?? null;
        $actualHost = $actual['host'] ?? null;

        if ($expectedHost === null || $actualHost === null
            || strcasecmp($expectedHost, $actualHost) !== 0) {
            return false;
        }

        $expectedScheme = strtolower((string) ($expected['scheme'] ?? 'http'));
        $actualScheme = strtolower((string) ($actual['scheme'] ?? ''));

        if ($expectedScheme !== $actualScheme) {
            return false;
        }

        return $this->portOf($actual, $actualScheme) === $this->portOf($expected, $expectedScheme);
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function portOf(array $parts, string $scheme): int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }

        return $scheme === 'https' ? 443 : 80;
    }
}
