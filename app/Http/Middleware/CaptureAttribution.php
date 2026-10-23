<?php

namespace App\Http\Middleware;

use App\Services\Attribution\AttributionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * First-touch attribution capture (spec Section 16). Runs only on
 * the marketing and auth route groups it is attached to, never on
 * /refer or the dashboard. On a tagged landing it sets the
 * server-side er_attrib cookie (first touch wins) and records E1;
 * a visitor who declined on the consent banner is never captured.
 */
class CaptureAttribution
{
    public function __construct(private AttributionService $attribution) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->method() !== 'GET' || ! $this->attribution->captureAllowed($request)) {
            return $response;
        }

        if ($this->attribution->captured($request) !== null) {
            return $response;
        }

        $payload = $this->attribution->extract($request);

        if ($payload === null) {
            return $response;
        }

        $this->attribution->record('E1', null, [
            'landing_path' => $payload['landing_path'],
            'source' => $payload['source'],
            'campaign' => $payload['campaign'],
        ]);

        $response->headers->setCookie(Cookie::create(
            $this->attribution->cookieName(),
            (string) json_encode($payload),
            now()->addDays((int) config('attribution.cookie_days'))->timestamp,
            (string) config('session.path'),
            null,
            (bool) config('session.secure'),
            true,
            false,
            'lax',
        ));

        return $response;
    }
}
